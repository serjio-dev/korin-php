<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Syntax\Node;
use Korin\Gedcom\Version;

/**
 * A live view of an event of an individual or a family (BIRT, MARR, EVEN …): its date, place,
 * age and type. Dates and ages are read and written by the rules of the document's version: in
 * 7.0 their phrase is the PHRASE substructure, in 5.x part of the value.
 */
final readonly class Event
{
    /** @internal use {@see Individual::events()} and the like */
    public function __construct(private Node $node) {}

    public function node(): Node
    {
        return $this->node;
    }

    public function tag(): string
    {
        return $this->node->tag();
    }

    /** TYPE: what kind of event an EVEN is, or a detail of another one. */
    public function type(): ?string
    {
        return $this->node->first('TYPE')?->value();
    }

    /** Null without a DATE, or with one that is not a valid date of the version (its text is on {@see node()}). */
    public function date(): ?GedcomDate
    {
        $date = $this->node->first('DATE');
        if ($date === null) {
            return null;
        }
        $parsed = GedcomDate::tryParse((string) $date->value(), $this->version());

        return $this->version() === Version::V7_0 ? $parsed?->withPhrase($date->first('PHRASE')?->value()) : $parsed;
    }

    /**
     * Replaces the DATE, its phrase included; null removes it.
     *
     * @throws \Korin\Gedcom\Exception\InvalidValueException when the version cannot write the date
     */
    public function setDate(?GedcomDate $date): self
    {
        $this->setValue('DATE', $date?->toGedcom($this->version()), $date?->phrase);

        return $this;
    }

    public function place(): ?Place
    {
        $place = $this->node->first('PLAC')?->value();

        return $place === null || $place === '' ? null : Place::of($place);
    }

    public function setPlace(?Place $place): self
    {
        $this->setValue('PLAC', $place?->toGedcom(), null);

        return $this;
    }

    /** AGE of an individual's event; null without one or with one that is not a valid age of the version. */
    public function age(): ?Age
    {
        $age = $this->node->first('AGE');
        if ($age === null) {
            return null;
        }
        $parsed = Age::tryParse((string) $age->value(), $this->version());

        return $this->version() === Version::V7_0 ? $parsed?->withPhrase($age->first('PHRASE')?->value()) : $parsed;
    }

    /** @throws \Korin\Gedcom\Exception\InvalidValueException when the version cannot write the age */
    public function setAge(?Age $age): self
    {
        $this->setValue('AGE', $age?->toGedcom($this->version()), $age?->phrase);

        return $this;
    }

    private function version(): Version
    {
        return $this->node->document()->version();
    }

    /**
     * @param ?string $value null removes the substructure
     * @param ?string $phrase the 7.0 PHRASE under it
     */
    private function setValue(string $tag, ?string $value, ?string $phrase): void
    {
        $child = $this->node->first($tag);
        if ($value === null) {
            $child?->remove();

            return;
        }
        $child ??= $this->node->addChild($tag);
        $child->setValue($value === '' ? null : $value);
        if ($this->version() !== Version::V7_0) {
            return;
        }
        $existing = $child->first('PHRASE');
        if ($phrase === null) {
            $existing?->remove();
        } else {
            ($existing ?? $child->addChild('PHRASE'))->setValue($phrase);
        }
    }
}
