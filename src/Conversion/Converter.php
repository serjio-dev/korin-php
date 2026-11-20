<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Exception\IoException;
use Korin\Gedcom\Exception\UnsupportedVersionException;
use Korin\Gedcom\Io\Encoding;
use Korin\Gedcom\ReadOptions;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\IndexedDocument;
use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Syntax\Parser;
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
 * has converts nothing: the result is a copy, written as the input was; a 5.0–5.4 draft, read
 * as 5.5, is written as 5.5 (SPEC section 10: one step), with no rules of its own. Before each
 * step HEAD.GEDC.VERS names the version the step converts from: what the file declared ("4+",
 * "5.01") is not a value of the later versions.
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

    /** Bytes of output convertFile() collects before it writes them. */
    private const BUFFER = 65536;

    /** Bytes of the file convertFile() converts in one document. */
    private const BATCH = 65536;

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
        if (!$to->isWritable()) {
            throw new UnsupportedVersionException("GEDCOM {$to->value} is read only: convert to 5.5 or later");
        }
        if ($from === $to && !$this->isDraft($document->head())) {
            $copy = $document->copy();

            return new ConversionResult(new LossReport($from, $to), (new Writer())->serialize($copy), new ReadOptions(), $copy);
        }
        [$route, $rules] = $this->plan($from, $to);
        $prepass = $this->needsPrepass($rules) ? Prepass::of($document) : null;

        $work = $document->copy();
        $context = new Context($document, $work, $options, $prepass);
        $this->convertRecords($work, $context, $route, $rules);
        $work->setVersion($to);

        $encoding = self::ENCODINGS[$to->value] ?? throw new UnsupportedVersionException("GEDCOM {$to->value} is read only");
        $this->stamp($work, $to, $encoding);
        $bytes = (new Writer())->serialize($work, WriteOptions::normalize()->withEncoding($encoding)->withLineEnding($document->lineEnding()));

        return new ConversionResult(new LossReport($from, $to, $context->losses()), $bytes, (new ReadOptions())->withVersion($to)->withEncoding($encoding));
    }

    /**
     * Converts an opened file record by record and writes the output as it goes (SPEC section 7,
     * "Streaming conversion"): byte for byte the output and the losses of {@see self::convert()}
     * of the whole document. In memory: the index, one record and its working copy, HEAD
     * converted (rules of other records read it), and the records the conversion adds, which
     * go before TRLR, in php://temp until TRLR is written. HEAD takes the records it converts
     * with it from the file: the SUBN records 7.0 and 5.5.5 keep in HEAD._SUBN, the SUBM record
     * 5.5.5 puts after HEAD. A file whose first record is not HEAD is converted whole.
     *
     * @param resource $out written from its current position
     *
     * @throws ConversionException when there is no route to the version, or at a loss under LossPolicy::Fail
     */
    public function convertFile(IndexedDocument $input, mixed $out, Version $to, ConvertOptions $options = new ConvertOptions()): LossReport
    {
        $from = $input->version();
        if (!$to->isWritable()) {
            throw new UnsupportedVersionException("GEDCOM {$to->value} is read only: convert to 5.5 or later");
        }
        if ($from === $to && !$this->isDraft($input->head())) {
            $input->copyTo($out);

            return new LossReport($from, $to);
        }
        [$route, $rules] = $this->plan($from, $to);
        if (count($input) === 0 || $input->tagAt(0) !== 'HEAD') {
            // Rules read HEAD as the first record; without it there, the order of the output is the whole document's.
            $result = $this->convert($input->load(), $to, $options);
            $this->write($out, $result->output());

            return $result->losses();
        }

        $prepass = $this->needsPrepass($rules) ? $this->prepass($input) : null;
        $context = new Context(Document::create($from), Document::create($from), $options, $prepass, static function () use ($input): array {
            $tags = [];
            foreach ($input->records() as $record) {
                $document = $record->document();
                foreach ($document->walk() as $id) {
                    $tag = $document->tagOf($id);
                    if ($tag !== '' && $tag[0] === '_') {
                        $tags[$tag] = true;
                    }
                }
            }

            return $tags;
        });
        $encoding = self::ENCODINGS[$to->value] ?? throw new UnsupportedVersionException("GEDCOM {$to->value} is read only");
        $write = WriteOptions::normalize()->withEncoding($encoding)->withLineEnding($input->lineEnding());
        $writer = new Writer();
        $added = $this->temporary();
        $tail = null;
        $buffer = '';
        $consumed = [];

        // HEAD, with the records its rules take from the file.
        $pulled = $this->pulled($input, $rules);
        $source = (new Parser())->parseRecords(
            array_map(static fn(int $i): array => [$input->bytesAt($i), $input->lineAt($i)], [0, ...$pulled]),
            $input->encoding(),
            $from,
            $input->hasBom(),
            $input->lineEnding(),
            new ReadOptions(),
        );
        $source->freeze();
        $work = $source->copy();
        $ids = $work->childIds(0);
        $byId = array_combine($ids, [0, ...$pulled]);
        // The records of the file between HEAD and those taken along: a record HEAD's rules want after HEAD must move.
        $gap = ($pulled[0] ?? 1) > 1 ? $work->createNode(0, null, '', null, $ids[1]) : null;
        $submissions = array_values(array_filter($ids, static fn(int $id): bool => $work->tagOf($id) === 'SUBN'));
        $context->unit($source, $work, $prepass === null ? null : new Prepass($prepass->xrefs, $submissions, $prepass->languages));
        $done = [];
        $this->convertRecord($work, $ids[0], 0, $context, $route, $rules, $done);
        $consumed[0] = true;
        // A record a rule moved right after HEAD is converted next, as in the whole document.
        $next = $work->nextRecord($ids[0]);
        if ($next !== null && $next !== $gap && isset($byId[$next])) {
            $this->convertRecord($work, $next, 0, $context, $route, $rules, $done);
        }
        foreach ($byId as $id => $i) {
            if (isset($done[$id]) || !$work->contains($id)) {
                $consumed[$i] = true;
            } elseif ($id !== $ids[0]) {
                // Taken along for nothing: converted where it is in the file.
                $work->removeNode($id);
            }
        }
        if ($gap !== null) {
            $work->removeNode($gap);
        }
        $context->setHeadDocument($work);
        $stamped = $work->copy();
        $stamped->setVersion($to);
        $this->stamp($stamped, $to, $encoding);
        [$records, $new] = $this->split($stamped);
        $this->write($out, $writer->serialize($stamped, $write, $records));
        $write = $write->withBom(false);
        if ($new !== []) {
            $this->write($added, $writer->serialize($stamped, $write, $new));
        }

        for ($i = 1, $count = count($input); $i < $count;) {
            // Records in batches of some size, parsed and written together; the first TRLR starts one.
            $batch = [];
            for ($size = 0; $i < $count && $size < self::BATCH; ++$i) {
                if (isset($consumed[$i])) {
                    continue;
                }
                if ($tail === null && $batch !== [] && $input->tagAt($i) === 'TRLR') {
                    break;
                }
                $batch[] = $i;
                $size += $input->sizeAt($i);
            }
            if ($batch === []) {
                continue;
            }
            $source = $input->recordsAt($batch);
            $work = $source->copy();
            $context->unit($source, $work, $prepass === null ? null : new Prepass($prepass->xrefs, [], $prepass->languages));
            $done = [];
            foreach ($work->childIds(0) as $record) {
                if (!isset($done[$record]) && $work->contains($record)) {
                    $this->convertRecord($work, $record, 0, $context, $route, $rules, $done);
                }
            }
            $work->setVersion($to);
            [$records, $new] = $this->split($work);
            if ($tail === null && $input->tagAt($batch[0]) === 'TRLR') {
                // Records the conversion adds go before the first TRLR: what follows waits for them.
                $this->write($out, $buffer);
                $buffer = '';
                $tail = $this->temporary();
            }
            // Written in pieces of some size, not batch by batch.
            $buffer .= $writer->serialize($work, $write, $records);
            if (strlen($buffer) >= self::BUFFER) {
                $this->write($tail ?? $out, $buffer);
                $buffer = '';
            }
            if ($new !== []) {
                $this->write($added, $writer->serialize($work, $write, $new));
            }
        }
        $this->write($tail ?? $out, $buffer);
        $this->append($out, $added);
        if ($tail !== null) {
            $this->append($out, $tail);
        }

        return new LossReport($from, $to, $context->losses());
    }

    /**
     * The steps from one version to the other and their rules, new for this conversion (rules
     * keep state between the records of one input); the first step also drops source lines.
     *
     * @return array{list<Step>, list<list<Rule>>} no steps for a draft to 5.5
     */
    private function plan(Version $from, Version $to): array
    {
        $route = $from === $to ? [] : $this->route($from, $to) ?? throw new ConversionException("Conversion from {$from->value} to {$to->value} is not supported yet");
        /** @var list<list<Rule>> $rules by step */
        $rules = array_map(static fn(Step $step): array => $step->rules(), $route);
        if ($rules !== []) {
            $rules[0] = [new Rule\SourceLines(), ...$rules[0]];
        }

        return [$route, $rules];
    }

    /** @param list<list<Rule>> $rules */
    private function needsPrepass(array $rules): bool
    {
        foreach (array_merge(...$rules) as $rule) {
            if ($rule->needsPrepass()) {
                return true;
            }
        }

        return false;
    }

    /** {@see Prepass::of()} from the index: SUBN records are numbered by the document they are converted in. */
    private function prepass(IndexedDocument $input): Prepass
    {
        $languages = [];
        for ($i = 0, $count = count($input); $i < $count; ++$i) {
            $tag = $input->tagAt($i);
            if ($tag === 'HEAD' || $tag === 'SUBM') {
                foreach ($input->recordAt($i, cache: false)->all('LANG') as $language) {
                    $languages[] = (string) $language->value();
                }
            }
        }

        return new Prepass(array_fill_keys(array_keys($input->xrefs()), true), [], $languages);
    }

    /**
     * Records HEAD's rules take from the file: the SUBN records (7.0, 5.5.5: HEAD._SUBN) and the
     * SUBM records that may be the 5.5.5 submitter, the one HEAD.SUBM points to and the first
     * one with an xref.
     *
     * @param list<list<Rule>> $rules
     *
     * @return list<int> record numbers in file order
     */
    private function pulled(IndexedDocument $input, array $rules): array
    {
        $submissions = $submitters = false;
        foreach (array_merge(...$rules) as $rule) {
            $submissions = $submissions || $rule instanceof Rule\V551ToV70\Submissions;
            $submitters = $submitters || $rule instanceof Rule\V551ToV555\Submitters;
        }
        $pulled = [];
        $pointer = $submitters ? $input->recordAt(0)->first('SUBM')?->pointer() : null;
        $pointed = $pointer === null ? null : ($input->xrefs()[$pointer] ?? null);
        $firstFound = false;
        for ($i = 1, $count = count($input); $i < $count; ++$i) {
            $tag = $input->tagAt($i);
            if ($submissions && $tag === 'SUBN') {
                $pulled[] = $i;
            } elseif ($submitters && $tag === 'SUBM') {
                $first = !$firstFound && $input->recordAt($i, cache: false)->xref() !== null;
                $firstFound = $firstFound || $first;
                if ($first || $i === $pointed) {
                    $pulled[] = $i;
                }
            }
        }

        return $pulled;
    }

    /**
     * @return array{list<int>, list<int>} records of a converted document from the file, and those the conversion added
     */
    private function split(Document $document): array
    {
        $records = $new = [];
        foreach ($document->childIds(0) as $id) {
            if ($document->lineOf($id) > 0) {
                $records[] = $id;
            } else {
                $new[] = $id;
            }
        }

        return [$records, $new];
    }

    /** @return resource */
    private function temporary(): mixed
    {
        $handle = fopen('php://temp', 'w+b');

        return $handle !== false ? $handle : throw new IoException('Cannot create a temporary stream');
    }

    /** @param resource $out */
    private function write(mixed $out, string $bytes): void
    {
        if ($bytes !== '' && fwrite($out, $bytes) !== strlen($bytes)) {
            throw new IoException('Cannot write the output');
        }
    }

    /**
     * @param resource $out
     * @param resource $from read from its start, then closed
     */
    private function append(mixed $out, mixed $from): void
    {
        rewind($from);
        if (stream_copy_to_stream($from, $out) === false) {
            throw new IoException('Cannot write the output');
        }
        fclose($from);
    }

    /**
     * Converts the records of a document in document order, each through every step of the route,
     * and after each the records its rules added, through the steps they still need (depth
     * first). The order is that of the output: a record a rule moves right after the one it
     * converts (5.5.5 SUBM after HEAD) is converted next, and convertFile() can follow it record
     * by record.
     *
     * @param list<Step>       $route
     * @param list<list<Rule>> $rules by step
     */
    private function convertRecords(Document $work, Context $context, array $route, array $rules): void
    {
        /** @var array<int, true> $done */
        $done = [];
        $after = 0;
        while (($record = $work->nextRecord($after)) !== null) {
            if (!isset($done[$record])) {
                $this->convertRecord($work, $record, 0, $context, $route, $rules, $done);
            }
            // A record its own rules removed (SUBN, moved into HEAD) is not one to continue after.
            if ($work->contains($record)) {
                $after = $record;
            }
        }
    }

    /**
     * @param list<Step>       $route
     * @param list<list<Rule>> $rules by step
     * @param array<int, true> $done  records converted, updated
     */
    private function convertRecord(Document $work, int $record, int $first, Context $context, array $route, array $rules, array &$done): void
    {
        $done[$record] = true;
        /** @var list<array{int, int}> $added record and the first step it needs */
        $added = [];
        for ($step = $first; $step < count($route) && $work->contains($record); ++$step) {
            $work->setVersion($route[$step]->from());
            $vers = $work->tagOf($record) === 'HEAD' ? $work->node($record)->first('GEDC')?->first('VERS') : null;
            if ($vers !== null && $vers->value() !== $route[$step]->from()->value) {
                $vers->setValue($route[$step]->from()->value);
            }
            $context->enter($route[$step], $record, $step === 0);
            foreach ($rules[$step] as $rule) {
                if (!$work->contains($record)) {
                    break;
                }
                $rule->record($work, $record, $context);
            }
            // Records added in this step, even by a rule that then removed its own record.
            foreach ($context->takeAdded() as [$id, $again]) {
                $added[] = [$id, $again ? $step : $step + 1];
            }
        }
        foreach ($added as [$id, $step]) {
            if ($step < count($route)) {
                $this->convertRecord($work, $id, $step, $context, $route, $rules, $done);
            } else {
                $done[$id] = true;
            }
        }
    }

    /**
     * HEAD.GEDC.VERS of the target and, in 5.x, HEAD.CHAR of the output encoding; created when
     * missing. A CHAR that changes loses its substructures (the VERS of the old character set).
     * 5.x requires GEDC.FORM and HEAD.SOUR, the system that wrote the file: a missing FORM is
     * LINEAGE-LINKED, after GEDC.VERS, and a missing SOUR is Korin, the system writing it.
     */
    private function stamp(Document $document, Version $to, Encoding $encoding): void
    {
        $head = $document->head() ?? $document->node($document->createNode(0, null, 'HEAD', null, $document->childIds(0)[0] ?? null));
        $gedc = $head->first('GEDC') ?? $head->addChild('GEDC');
        $this->set($gedc, 'VERS', $to->value);
        if ($to === Version::V7_0) {
            return;
        }
        $this->set($head, 'CHAR', $encoding === Encoding::Utf8 ? 'UTF-8' : 'UNICODE');
        if ($gedc->first('FORM') === null) {
            $children = $gedc->children();
            $vers = array_search('VERS', array_map(static fn(Node $child): string => $child->tag(), $children), true);
            $gedc->addChild('FORM', 'LINEAGE-LINKED', $vers === false ? null : $vers + 1);
        }
        if ($head->first('SOUR') === null) {
            $head->addChild('SOUR', 'Korin', 0);
        }
    }

    /** Whether HEAD declares a 5.0–5.4 draft, which is read as 5.5 (Version::fromDeclared()). */
    private function isDraft(?Node $head): bool
    {
        $declared = $head?->first('GEDC')?->first('VERS')?->value();

        return $declared !== null && trim($declared) !== '5.5' && Version::fromDeclared($declared) === Version::V5_5;
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
