<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/**
 * A live view of a FAM record: partners, children and events. Linking an individual also adds
 * the pointer back (FAMS, FAMC), so the links stay mutual.
 */
final class Family extends RecordView
{
    /** Family events of 5.5, 5.5.1, 5.5.5 and 7.0. */
    public const EVENTS = [
        'ANUL' => true, 'CENS' => true, 'DIV' => true, 'DIVF' => true, 'ENGA' => true, 'MARB' => true,
        'MARC' => true, 'MARR' => true, 'MARL' => true, 'MARS' => true, 'EVEN' => true,
    ];

    public function husband(): ?Individual
    {
        return $this->individuals('HUSB')[0] ?? null;
    }

    public function wife(): ?Individual
    {
        return $this->individuals('WIFE')[0] ?? null;
    }

    /** @return list<Individual> HUSB and WIFE, in order */
    public function partners(): array
    {
        $partners = [];
        foreach ($this->node->children() as $child) {
            $tag = $child->tag();
            $pointer = $child->pointer();
            if (($tag === 'HUSB' || $tag === 'WIFE') && $pointer !== null && $pointer !== '@VOID@') {
                $record = $this->lookUp($pointer, 'INDI');
                if ($record !== null) {
                    $partners[] = new Individual($record, $this->file);
                }
            }
        }

        return $partners;
    }

    /** @return list<Individual> */
    public function children(): array
    {
        return $this->individuals('CHIL');
    }

    /** @return list<string> HUSB, WIFE and CHIL pointers; no other record is read */
    public function memberXrefs(): array
    {
        return array_values(array_unique([...$this->pointers('HUSB'), ...$this->pointers('WIFE'), ...$this->pointers('CHIL')]));
    }

    /** Points HUSB to the individual and adds its FAMS. */
    public function setHusband(Individual $husband): self
    {
        return $this->setPartner('HUSB', $husband);
    }

    /** Points WIFE to the individual and adds its FAMS. */
    public function setWife(Individual $wife): self
    {
        return $this->setPartner('WIFE', $wife);
    }

    /** Adds CHIL and the child's FAMC, unless they are there. */
    public function addChild(Individual $child): self
    {
        $xref = $this->member($child);
        if (!in_array($xref, $this->pointers('CHIL'), true)) {
            $this->node->addChild('CHIL', $xref);
        }
        $this->link($child, 'FAMC');

        return $this;
    }

    /** Removes CHIL and the child's FAMC to this family. */
    public function removeChild(Individual $child): self
    {
        $xref = $this->member($child);
        foreach ($this->node->all('CHIL') as $line) {
            if ($line->pointer() === $xref) {
                $line->remove();
            }
        }
        foreach ($child->node()->all('FAMC') as $line) {
            if ($line->pointer() === $this->xref()) {
                $line->remove();
            }
        }

        return $this;
    }

    /** @return list<Event> the events, or those with these tags, in order */
    public function events(string ...$tags): array
    {
        return $this->eventsOf($tags === [] ? self::EVENTS : array_fill_keys($tags, true));
    }

    public function marriage(): ?Event
    {
        return $this->events('MARR')[0] ?? null;
    }

    /** Adds an event; one without a date or place is "Y", it happened. */
    public function addEvent(string $tag, ?GedcomDate $date = null, ?Place $place = null): Event
    {
        if (!isset(self::EVENTS[$tag])) {
            throw new InvalidValueException("{$tag} is not a family event");
        }
        $event = $this->addEventTo($tag, $date, $place);
        if ($date === null && $place === null && $tag !== 'EVEN') {
            $event->node()->setValue('Y');
        }

        return $event;
    }

    public function addMarriage(?GedcomDate $date = null, ?Place $place = null): Event
    {
        return $this->addEvent('MARR', $date, $place);
    }

    /** @return list<Individual> */
    private function individuals(string $tag): array
    {
        $individuals = [];
        foreach ($this->pointers($tag) as $xref) {
            $record = $this->lookUp($xref, 'INDI');
            if ($record !== null) {
                $individuals[] = new Individual($record, $this->file);
            }
        }

        return $individuals;
    }

    private function setPartner(string $tag, Individual $partner): self
    {
        $xref = $this->member($partner);
        ($this->node->first($tag) ?? $this->node->addChild($tag))->setPointer($xref);
        $this->link($partner, 'FAMS');

        return $this;
    }

    /** The xref of an individual of the same document. */
    private function member(Individual $individual): string
    {
        $xref = $individual->xref();
        if ($xref === null || $this->xref() === null || $individual->node()->document() !== $this->node->document()) {
            throw new InvalidValueException('The family and the individual must have xrefs and be in one document');
        }

        return $xref;
    }

    private function link(Individual $individual, string $tag): void
    {
        $xref = (string) $this->xref();
        foreach ($individual->node()->all($tag) as $line) {
            if ($line->pointer() === $xref) {
                return;
            }
        }
        $individual->node()->addChild($tag, $xref);
    }
}
