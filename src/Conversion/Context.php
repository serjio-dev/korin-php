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

    /** @var list<int> records added by the current step */
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
        $record = $this->document->createNode(0, $xref, $tag, null, $this->trailer);
        if ($value !== null) {
            $version = $this->document->version();
            $this->document->setVersion($this->step?->to() ?? $version);
            $this->document->node($record)->setValue($value);
            $this->document->setVersion($version);
        }
        $this->added[] = $record;

        return $record;
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
     * @return list<int> records added since the last call
     */
    public function takeAdded(): array
    {
        [$added, $this->added] = [$this->added, []];

        return $added;
    }
}
