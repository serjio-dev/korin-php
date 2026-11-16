<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;
use Korin\Gedcom\WriteOptions;
use Korin\Gedcom\Writer\Writer;

/**
 * Converts a document to another version along the chain of adjacent versions (SPEC section 7).
 * The input is not changed: the steps convert a copy record by record, then the copy is written
 * by the target version's rules; that output, read back on demand, is an ordinary document
 * whose preserve-mode output is the conversion output.
 *
 * Output conventions: HEAD.GEDC.VERS names the target; 5.x HEAD.CHAR names the output encoding
 * (UTF-8, for 5.5 UTF-16 as "UNICODE"); the BOM follows the target version; every line ends
 * with the source's dominant line terminator. Converting to the version a document already
 * has converts nothing: the result is a copy, written as the input was.
 */
final readonly class Converter
{
    /** Output encoding by target version (SPEC section 3, "Encodings for writing"). */
    private const ENCODINGS = [
        '5.5' => Encoding::Utf16Be,
        '5.5.1' => Encoding::Utf8,
        '5.5.5' => Encoding::Utf8,
        '7.0' => Encoding::Utf8,
    ];

    /** @var list<Step> */
    private array $steps;

    /** @param ?list<Step> $steps the library's steps when null */
    public function __construct(?array $steps = null)
    {
        $this->steps = $steps ?? Steps::all();
    }

    public function supports(Version $from, Version $to): bool
    {
        return $from === $to || $this->route($from, $to) !== null;
    }

    /** @throws ConversionException when there is no route to the version, or at a loss under LossPolicy::Fail */
    public function convert(Document $document, Version $to, ConvertOptions $options = new ConvertOptions()): ConversionResult
    {
        $from = $document->version();
        if ($from === $to) {
            $copy = $document->copy();

            return new ConversionResult(new LossReport($from, $to), (new Writer())->serialize($copy), new ReadOptions(), $copy);
        }
        $route = $this->route($from, $to) ?? throw new ConversionException("Conversion from {$from->value} to {$to->value} is not supported yet");

        /** @var list<list<Rule>> $rules by step */
        $rules = array_map(static fn(Step $step): array => $step->rules(), $route);
        $rules[0] = [new Rule\SourceLines(), ...$rules[0]];
        $prepass = null;
        foreach (array_merge(...$rules) as $rule) {
            if ($rule->needsPrepass()) {
                $prepass = Prepass::of($document);
                break;
            }
        }

        $work = $document->copy();
        $context = new Context($document, $work, $options, $prepass);
        /** @var list<array{int, int}> $pending record and the first step it still needs */
        $pending = array_map(static fn(int $id): array => [$id, 0], $work->childIds(0));
        for ($i = 0; $i < count($pending); ++$i) {
            [$record, $first] = $pending[$i];
            for ($step = $first; $step < count($route) && $work->contains($record); ++$step) {
                $work->setVersion($route[$step]->from());
                $context->enter($route[$step], $record, $step === 0);
                foreach ($rules[$step] as $rule) {
                    if (!$work->contains($record)) {
                        break;
                    }
                    $rule->record($work, $record, $context);
                }
                // Records added in this step, even by a rule that then removed its own record.
                foreach ($context->takeAdded() as [$added, $again]) {
                    $pending[] = [$added, $again ? $step : $step + 1];
                }
            }
        }
        $work->setVersion($to);

        $encoding = self::ENCODINGS[$to->value];
        $this->stamp($work, $to, $encoding);
        $bytes = (new Writer())->serialize($work, WriteOptions::normalize()->withEncoding($encoding)->withLineEnding($document->lineEnding()));

        return new ConversionResult(new LossReport($from, $to, $context->losses()), $bytes, (new ReadOptions())->withVersion($to)->withEncoding($encoding));
    }

    /**
     * HEAD.GEDC.VERS of the target and, in 5.x, HEAD.CHAR of the output encoding; created when
     * missing. A CHAR that changes loses its substructures (the VERS of the old character set).
     */
    private function stamp(Document $document, Version $to, Encoding $encoding): void
    {
        $head = $document->head() ?? $document->node($document->createNode(0, null, 'HEAD', null, $document->childIds(0)[0] ?? null));
        $gedc = $head->first('GEDC') ?? $head->addChild('GEDC');
        $this->set($gedc, 'VERS', $to->value);
        if ($to !== Version::V7_0) {
            $this->set($head, 'CHAR', $encoding === Encoding::Utf8 ? 'UTF-8' : 'UNICODE');
        }
    }

    private function set(Node $parent, string $tag, string $value): void
    {
        $node = $parent->first($tag);
        if ($node === null) {
            $parent->addChild($tag, $value);
        } elseif ($node->value() !== $value) {
            foreach ($node->children() as $child) {
                $child->remove();
            }
            $node->setValue($value);
        }
    }

    /**
     * The shortest chain of steps from one version to another (breadth-first).
     *
     * @return ?non-empty-list<Step>
     */
    private function route(Version $from, Version $to): ?array
    {
        /** @var array<string, list<Step>> $paths */
        $paths = [$from->value => []];
        $queue = [$from];
        while ($queue !== []) {
            $version = array_shift($queue);
            foreach ($this->steps as $step) {
                $next = $step->to();
                if ($step->from() !== $version || isset($paths[$next->value])) {
                    continue;
                }
                $paths[$next->value] = [...$paths[$version->value], $step];
                if ($next === $to) {
                    return $paths[$next->value];
                }
                $queue[] = $next;
            }
        }

        return null;
    }
}
