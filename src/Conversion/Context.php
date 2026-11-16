<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Syntax\Document;

/**
 * What a rule may use besides the record it converts: the options, the prepass, new xrefs and
 * records, and the loss report. Losses are located in the source document: the document being
 * converted is a copy of it, so a node keeps its id; a node added during the conversion is
 * located at its nearest ancestor from the source, a line that could not be parsed at the
 * structure it is in (the path of a file-level one is empty).
 *
 * @internal
 */
final class Context
{
    /** @var list<Loss> */
    private array $losses = [];

    /** @var array<string, true> xrefs handed out by newXref() */
    private array $allocated = [];

    private int $nextXref = 1;

    /** @var list<array{int, bool}> records added by the current step, and whether it converts them too */
    private array $added = [];

    private ?Step $step = null;

    /** TRLR, found once: new records go before it (at the end without one). */
    private ?int $trailer = null;

    private bool $trailerFound = false;

    public function __construct(
        private readonly Document $source,
        private readonly Document $document,
        public readonly ConvertOptions $options,
        private readonly ?Prepass $prepass,
    ) {}

    /** @throws \LogicException when no rule of the conversion declared needsPrepass() */
    public function prepass(): Prepass
    {
        return $this->prepass ?? throw new \LogicException('The prepass did not run: declare needsPrepass()');
    }

    /** The next free xref of the form @X<n>@ (SPEC section 7: "the next free n in document order"); needs the prepass. */
    public function newXref(): string
    {
        $taken = $this->prepass()->xrefs;
        do {
            $xref = '@X' . $this->nextXref++ . '@';
        } while (isset($taken[$xref]) || isset($this->allocated[$xref]));
        $this->allocated[$xref] = true;

        return $xref;
    }

    /**
     * A new record before TRLR, already in the target version of the current step (its value is
     * escaped and split into CONT lines for that version); the remaining steps convert it too.
     */
    public function addRecord(string $tag, ?string $xref = null, ?string $value = null): int
    {
        $record = $this->insertRecord($tag, $xref);
        if ($value !== null) {
            $this->setValue($record, $value);
        }
        $this->added[] = [$record, false];

        return $record;
    }

    /**
     * A new record before TRLR that the current step converts too, like a record of the input:
     * what a rule moves or writes into it is in the step's source version (a structure taken out
     * of a record into a record of its own).
     */
    public function extractRecord(string $tag, string $xref): int
    {
        $record = $this->insertRecord($tag, $xref);
        $this->added[] = [$record, true];

        return $record;
    }

    /** Sets a text value by the rules of the current step's target version: its "@" escaping, "\n" as CONT lines. */
    public function setValue(int $node, ?string $value): void
    {
        $this->document->node($node)->setValueFor($value, $this->step?->to() ?? $this->document->version());
    }

    /**
     * Reports data that the target version cannot hold as it was.
     *
     * @throws ConversionException under LossPolicy::Fail
     */
    public function loss(string $code, Severity $severity, int $node, StoredAs $storedAs, string $message): void
    {
        $origin = $node;
        while ($origin > 0 && !$this->source->contains($origin)) {
            $origin = $this->document->parentOf($origin);
        }
        $line = $origin > 0 ? $this->source->lineOf($origin) : 0;
        // A line that could not be parsed has no tag: it is located at the structure it is in.
        $located = $origin;
        while ($located > 0 && $this->source->isInvalid($located)) {
            $located = $this->source->parentOf($located);
        }
        $loss = new Loss($code, $severity, $located > 0 ? $this->source->node($located)->path() : '', $line > 0 ? $line : null, $storedAs, $message);
        if ($this->options->policy === LossPolicy::Fail) {
            throw new ConversionException("Conversion stopped by a loss: {$loss}", $loss);
        }
        $this->losses[] = $loss;
    }

    /** @return list<Loss> */
    public function losses(): array
    {
        return $this->losses;
    }

    /** @internal for Converter: the step whose rules run now */
    public function enter(Step $step): void
    {
        $this->step = $step;
    }

    /**
     * @internal for Converter
     *
     * @return list<array{int, bool}> records added since the last call, and whether the step that
     *                               added them converts them too
     */
    public function takeAdded(): array
    {
        [$added, $this->added] = [$this->added, []];

        return $added;
    }

    private function insertRecord(string $tag, ?string $xref): int
    {
        if (!$this->trailerFound || ($this->trailer !== null && !$this->document->contains($this->trailer))) {
            $this->trailerFound = true;
            $this->trailer = null;
            foreach ($this->document->childIds(0) as $id) {
                if ($this->document->tagOf($id) === 'TRLR') {
                    $this->trailer = $id;
                    break;
                }
            }
        }

        return $this->document->createNode(0, $xref, $tag, null, $this->trailer);
    }
}
