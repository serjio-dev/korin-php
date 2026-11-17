<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

use Korin\Gedcom\Exception\InvalidValueException;

/**
 * A live view of an INDI record: names, sex, events, and the families it is a child or a
 * spouse in. Values that are not valid for the version read as null; their text stays on
 * {@see node()}.
 */
final class Individual extends RecordView
{
    public const TAG = 'INDI';

    /** @var array<string, true> individual events of 5.5, 5.5.1, 5.5.5 and 7.0 */
    public const EVENTS = [
        'ADOP' => true, 'BAPM' => true, 'BARM' => true, 'BASM' => true, 'BIRT' => true, 'BLES' => true,
        'BURI' => true, 'CENS' => true, 'CHR' => true, 'CHRA' => true, 'CONF' => true, 'CREM' => true,
        'DEAT' => true, 'EMIG' => true, 'FCOM' => true, 'GRAD' => true, 'IMMI' => true, 'NATU' => true,
        'ORDN' => true, 'PROB' => true, 'RETI' => true, 'WILL' => true, 'EVEN' => true,
    ];

    /** The first NAME. */
    public function name(): ?PersonalName
    {
        $name = $this->node->first('NAME')?->value();

        return $name === null ? null : PersonalName::tryParse($name);
    }

    /** @return list<PersonalName> every valid NAME, in order */
    public function names(): array
    {
        $names = [];
        foreach ($this->node->all('NAME') as $node) {
            $name = PersonalName::tryParse((string) $node->value());
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Replaces the value of the first NAME, or adds one. Its pieces GIVN, SURN and NSFX that were
     * the parts of the old value follow the new one (removed when the new one has no such part);
     * pieces that said something else, and the other substructures, stay.
     */
    public function setName(PersonalName $name): self
    {
        $node = $this->node->first('NAME') ?? $this->node->addChild('NAME');
        $old = PersonalName::tryParse((string) $node->value());
        $node->setValue($name->toGedcom());
        if ($old === null) {
            return $this;
        }
        foreach (['GIVN' => [$old->given, $name->given], 'SURN' => [$old->surname, $name->surname], 'NSFX' => [$old->suffix, $name->suffix]] as $tag => [$was, $is]) {
            foreach ($node->all($tag) as $piece) {
                if ($piece->value() === $was) {
                    $is === null || $is === '' ? $piece->remove() : $piece->setValue($is);
                }
            }
        }

        return $this;
    }

    /** @param \Closure(PersonalName): PersonalName $edit applied to the first NAME */
    public function editName(\Closure $edit): self
    {
        return $this->setName($edit($this->name() ?? PersonalName::of('')));
    }

    public function sex(): ?Sex
    {
        $sex = $this->node->first('SEX')?->value();

        return $sex === null ? null : Sex::parse($sex, $this->version());
    }

    /** @throws InvalidValueException for X before 7.0 */
    public function setSex(Sex $sex): self
    {
        if (!$sex->isIn($this->version())) {
            throw new InvalidValueException("SEX {$sex->value} is not in GEDCOM {$this->version()->value}");
        }
        ($this->node->first('SEX') ?? $this->node->addChild('SEX'))->setValue($sex->value);

        return $this;
    }

    /** @return list<Event> the events, or those with these tags, in order */
    public function events(string ...$tags): array
    {
        return $this->eventsOf($tags === [] ? self::EVENTS : array_fill_keys($tags, true));
    }

    public function birth(): ?Event
    {
        return $this->events('BIRT')[0] ?? null;
    }

    public function death(): ?Event
    {
        return $this->events('DEAT')[0] ?? null;
    }

    public function addBirth(?GedcomDate $date = null, ?Place $place = null): Event
    {
        return $this->addEvent('BIRT', $date, $place);
    }

    public function addDeath(?GedcomDate $date = null, ?Place $place = null): Event
    {
        return $this->addEvent('DEAT', $date, $place);
    }

    /** @return list<string> FAMC and FAMS pointers; no other record is read */
    public function familyXrefs(): array
    {
        return array_values(array_unique([...$this->pointers('FAMC'), ...$this->pointers('FAMS')]));
    }

    /** @return list<Family> the families of FAMC */
    public function familiesAsChild(): array
    {
        return $this->families('FAMC');
    }

    /** @return list<Family> the families of FAMS */
    public function familiesAsSpouse(): array
    {
        return $this->families('FAMS');
    }

    /** @return list<Individual> HUSB and WIFE of the families of FAMC */
    public function parents(): array
    {
        $parents = [];
        foreach ($this->familiesAsChild() as $family) {
            array_push($parents, ...$family->partners());
        }

        return $this->distinct($parents);
    }

    /** @return list<Individual> the other partners of the families of FAMS */
    public function spouses(): array
    {
        $spouses = [];
        foreach ($this->familiesAsSpouse() as $family) {
            foreach ($family->partners() as $partner) {
                if ($partner->xref() !== $this->xref()) {
                    $spouses[] = $partner;
                }
            }
        }

        return $this->distinct($spouses);
    }

    /** @return list<Individual> CHIL of the families of FAMS */
    public function children(): array
    {
        $children = [];
        foreach ($this->familiesAsSpouse() as $family) {
            array_push($children, ...$family->children());
        }

        return $this->distinct($children);
    }

    /**
     * @param list<Individual> $individuals
     *
     * @return list<Individual> each one once, in order: a FAMC repeated (another PEDI) gives the same parents
     */
    private function distinct(array $individuals): array
    {
        $seen = [];
        foreach ($individuals as $individual) {
            $seen[(string) $individual->xref()] ??= $individual;
        }

        return array_values($seen);
    }

    /** @return list<Family> */
    private function families(string $tag): array
    {
        $families = [];
        foreach (array_unique($this->pointers($tag)) as $xref) {
            $record = $this->lookUp($xref, 'FAM');
            if ($record !== null) {
                $families[] = new Family($record, $this->file);
            }
        }

        return $families;
    }
}
