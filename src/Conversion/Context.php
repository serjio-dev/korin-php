<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion;

use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Exception\ConversionException;
use Korin\Gedcom\Severity;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;

/**
 * What a rule may use besides the record it converts: the options, the prepass, new xrefs and
 * records, and the loss report. Losses are located in the source document: the document being
 * converted is a copy of it, so a node keeps its id; a node added during the conversion is
 * located at its nearest ancestor from the source, a line that could not be parsed at the
 * structure it is in (the path of a file-level one is empty).
 *
 * A route of several steps converts each record through every step in turn. The first step
 * looks structures up in the input; a later one in the record as it was when the step began.
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

    /** @var array<int, ?string> structure URIs of the record last looked up by sourceElement() and of its substructures */
    private array $uris = [];

    /** @var ?array<string, true> extension tags of the input, collected on first use */
    private ?array $inputExtensions = null;

    /** @var array<int, bool> whether a structure of the document being converted is an extension or inside one */
    private array $extensions = [];

    private int $urisRecord = 0;

    /** Whether the current step is the first of the route: its source document is the input. */
    private bool $first = true;

    /** Later steps: nodes with a lower id were in the record when the step began. */
    private int $watermark = 0;

    /**
     * @var array<int, array{int, array<int, ?string>}> later steps: for a record extractRecord()
     *                                                   added, the watermark and structure URIs of the record it was taken from
     */
    private array $extracted = [];

    /** @var array<int, ?string> later steps: the structure URIs of the record as the step began */
    private array $stepUris = [];

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
     * the structures a rule moves into it are converted with it (a structure taken out of a
     * record into a record of its own). What a rule writes into it is written by the target
     * version's rules (setValue()), like every structure a rule creates: syntax rules skip
     * structures that are not from the source.
     */
    public function extractRecord(string $tag, string $xref): int
    {
        $record = $this->insertRecord($tag, $xref);
        $this->added[] = [$record, true];
        if (!$this->first) {
            $this->extracted[$record] = [$this->watermark, $this->stepUris];
        }

        return $record;
    }

    /**
     * Whether a structure is in the source of the current step (the input; in a later step, the
     * record as the step began): one a rule of the step created is written by the target
     * version's rules already (setValue()), so a syntax rule must not rewrite it.
     */
    public function isFromSource(int $node): bool
    {
        return $this->first ? $this->source->contains($node) : $node < $this->watermark && $this->document->contains($node);
    }

    /**
     * A text value read by the rules of the current step's target version: what setValue() wrote,
     * and any value once the step's syntax rule has rewritten it (5.5.1 → 7.0: TextValues).
     */
    public function value(int $node): ?string
    {
        return $this->document->node($node)->valueFor($this->step?->to() ?? $this->document->version());
    }

    /** A new last substructure with a text value written by setValue(): the PHRASE a value rule keeps the original in, say. */
    public function addText(int $parent, string $tag, string $value): int
    {
        $node = $this->document->createNode($parent, null, $tag, null);
        $this->setValue($node, $value);

        return $node;
    }

    /**
     * Whether the input has a structure with this extension tag. The first call walks every line
     * of the input once; convertFile() will take the tags from its prepass (SPEC section 7).
     */
    public function inputHasExtension(string $tag): bool
    {
        if ($this->inputExtensions === null) {
            $this->inputExtensions = [];
            foreach ($this->source->walk() as $id) {
                $found = $this->source->tagOf($id);
                if ($found[0] === '_') {
                    $this->inputExtensions[$found] = true;
                }
            }
        }

        return isset($this->inputExtensions[$tag]);
    }

    /** Sets a text value by the rules of the current step's target version: its "@" escaping, "\n" as CONT lines. */
    public function setValue(int $node, ?string $value): void
    {
        $this->document->node($node)->setValueFor($value, $this->step?->to() ?? $this->document->version());
    }

    /**
     * The payload element of a structure in the current step's source version ("<DATE_VALUE>"
     * in 5.5.1; "<MULTIMEDIA_FILE_REFN>" for "<MULTIMEDIA_FILE_REFN>|<NULL>": a value that is
     * there is that element), found by its place in the source document: a structure a rule
     * moved keeps what it was. Null for a structure the conversion added, one the source version does not
     * define there, and anything inside an extension of the document being converted.
     *
     * In a later step of a route, the place is the one the structure had when the step began.
     */
    public function sourceElement(int $node): ?string
    {
        $payload = $this->sourceStructure($node)?->payload;

        return $payload === null ? null : str_replace('|<NULL>', '', $payload);
    }

    /**
     * A structure the target version has no place for: kept as an extension, "_" and its tag,
     * with its substructures, or removed under LossPolicy::DropWithReport; reported either way.
     *
     * @return bool whether the structure is still there
     *
     * @throws ConversionException under LossPolicy::Fail
     */
    public function unsupported(int $node, string $code, string $message): bool
    {
        if ($this->options->policy === LossPolicy::DropWithReport) {
            $this->loss($code, Severity::Warning, $node, StoredAs::Dropped, $message);
            $this->document->removeNode($node);

            return false;
        }
        $this->loss($code, Severity::Warning, $node, StoredAs::Extension, $message);
        $this->document->setTag($node, '_' . $this->document->tagOf($node));
        unset($this->extensions[$node]);

        return true;
    }

    /**
     * The structure of a node in the current step's source version, by its place in the source
     * document (like sourceElement()): null for a structure the conversion added, one the
     * version does not define there, and anything inside an extension.
     */
    public function sourceStructure(int $node): ?Structure
    {
        if (!$this->first) {
            $uri = $this->stepUris[$node] ?? null;

            return $uri === null ? null : $this->profile()->structure($uri);
        }
        if (!$this->source->contains($node) || $this->inExtension($this->document->parentOf($node))) {
            return null;
        }
        $uri = $this->sourceUri($node);

        return $uri === null ? null : $this->profile()->structure($uri);
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

    /** @internal for Converter: the step whose rules run now, and the record they convert */
    public function enter(Step $step, int $record, bool $first): void
    {
        $this->step = $step;
        $this->first = $first;
        if ($first) {
            return;
        }
        $this->extensions = [];
        if (isset($this->extracted[$record])) {
            // Taken out of a record of the step: its structures are where they were in that one.
            [$this->watermark, $this->stepUris] = $this->extracted[$record];
            unset($this->extracted[$record]);

            return;
        }
        $this->watermark = $this->document->nextId();
        // The record as the step begins: the rules move and rename its structures.
        $profile = $this->profile();
        $this->stepUris = [$record => $profile->record($this->document->tagOf($record))?->uri];
        $stack = [$record];
        while ($stack !== []) {
            $id = array_pop($stack);
            $uri = $this->stepUris[$id];
            if ($uri === null || !$this->document->hasChildren($id)) {
                continue;
            }
            $structure = $profile->structure($uri);
            foreach ($this->document->childIds($id) as $child) {
                if ($child < $this->watermark) {
                    $this->stepUris[$child] = $structure?->substructure($this->document->tagOf($child))?->uri;
                    $stack[] = $child;
                }
            }
        }
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

    private function profile(): Profile
    {
        return Profile::for($this->step?->from() ?? $this->source->version());
    }

    /**
     * Whether a structure of the document being converted is an extension or inside one, by the
     * tags it has now; cached for one record at a time, so ask before renaming its ancestors.
     */
    public function inExtension(int $id): bool
    {
        if ($id === 0) {
            return false;
        }
        if (isset($this->extensions[$id])) {
            return $this->extensions[$id];
        }
        $parent = $this->document->parentOf($id);
        if ($parent === 0) {
            $this->extensions = [];
        }

        return $this->extensions[$id] = str_starts_with($this->document->tagOf($id), '_') || $this->inExtension($parent);
    }

    /** Structure URI of a source node; the cache holds one record at a time. */
    private function sourceUri(int $id): ?string
    {
        if (array_key_exists($id, $this->uris)) {
            return $this->uris[$id];
        }
        $parent = $this->source->parentOf($id);
        if ($parent === 0) {
            if ($id !== $this->urisRecord) {
                $this->uris = [];
                $this->urisRecord = $id;
            }

            return $this->uris[$id] = $this->profile()->record($this->source->tagOf($id))?->uri;
        }
        $above = $this->sourceUri($parent);

        return $this->uris[$id] = $above === null ? null : $this->profile()->structure($above)?->substructure($this->source->tagOf($id))?->uri;
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
