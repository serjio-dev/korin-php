<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;
use Korin\Gedcom\Syntax\Escaping;
use Korin\Gedcom\Version;

/**
 * Values between 5.x versions: an enumeration value the target does not list is spelled as the
 * target spells it where it has one (5.5 FORM jpeg ↔ 5.5.1 jpg), and otherwise, like a missing
 * pointer the target requires (5.5.1 REPO without one → 5.5), makes the structure an extension
 * with its tag and value (5.5.1 SEX U → 5.5 _SEX U), or drops it under
 * LossPolicy::DropWithReport (KL3001); converting back gives the structure back (Extensions).
 * A value that does not fit the source version either stays as it is. The Y of an event is
 * left to Structures.
 *
 * @internal
 */
final class Values implements NodeRule
{
    /** @var ?list<string> */
    private ?array $tags = null;

    /** @var array<string, ?Structure> target structures by tag path ("INDI BIRT DATE") */
    private array $targets = [];

    /**
     * @param array<string, array<string, string>> $spellings by target element ("<MULTIMEDIA_FORMAT>"): source value (lowercase) => target value
     * @param bool                                 $grammars  whether dates, ages and lengths must fit too (to and from 5.5.5)
     * @param ?\Closure(string, string): ?string   $respell   target grammar ("AGE_AT_EVENT") and value => the target's spelling of a value that does not fit (5.5.5 "<1y" → "< 1y")
     * @param list<string>                         $kept      target elements whose values stay when none of their spellings fits (5.5.1 programs write FORM png)
     */
    public function __construct(
        private readonly Version $from,
        private readonly Version $to,
        private readonly array $spellings = [],
        private readonly bool $grammars = false,
        private readonly ?\Closure $respell = null,
        private readonly array $kept = [],
    ) {}

    public function needsPrepass(): bool
    {
        return false;
    }

    /** The tags of the structures with an enumeration or a pointer in either version; with grammars, also those with a grammar or a literal. */
    public function tags(): array
    {
        if ($this->tags === null) {
            $tags = [];
            foreach ([Profile::for($this->from), Profile::for($this->to)] as $profile) {
                foreach (self::structures($profile) as $structure) {
                    if (Payloads::enumerated($profile, $structure) !== null || $structure->pointerTarget() !== null
                        || ($this->grammars && $this->typed($profile, $structure))) {
                        $tags[$structure->tag] = true;
                    }
                }
            }
            $this->tags = array_keys($tags);
        }

        return $this->tags;
    }

    public function node(Document $document, int $id, Context $context): bool
    {
        $parent = $document->parentOf($id);
        // HEAD.CHAR names the output encoding (Converter).
        if ($parent === 0 || $document->isInvalid($id) || $context->inExtension($id) || ($document->tagOf($id) === 'CHAR' && $document->parentOf($parent) === 0)) {
            return true;
        }
        $path = $document->tagOf($id);
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            $path = $document->tagOf($above) . ' ' . $path;
        }
        $profile = Profile::for($this->to);
        $target = array_key_exists($path, $this->targets) ? $this->targets[$path] : ($this->targets[$path] = $profile->resolve(explode(' ', $path)));
        $payload = $document->payloadOf($id);
        $value = $this->value($document, $id, $payload, $context);
        if ($target === null || Payloads::fits($profile, $target, $value, $this->grammars)) {
            return true;
        }
        $source = $context->sourceStructure($id);
        if ($source === null || !Payloads::fits(Profile::for($this->from), $source, $value, $this->grammars) || ($value === 'Y' && $target->payload === null)) {
            return true;
        }
        $element = Payloads::enumerated($profile, $target) ?? (preg_match('/<[A-Z_0-9]+>/', (string) $target->payload, $m) === 1 ? $m[0] : '');
        $spelled = $value === null ? null : $this->spellings[$element][strtolower($value)] ?? ($this->respell === null ? null : ($this->respell)($profile->type($element) ?? '', $value));
        $kept = in_array($element, $this->kept, true);
        if ($spelled !== null && ($kept || Payloads::fits($profile, $target, $spelled, $this->grammars))) {
            $context->setValue($id, $spelled);

            return true;
        }
        if ($kept) {
            return true;
        }
        $tag = $document->tagOf($id);

        return $context->unsupported($id, 'KL3001', $value === null ? "{$this->to->value} {$tag} needs a value" : "{$this->to->value} {$tag} has no such value");
    }

    /** The value of a structure; the payload but for continuation lines and escapes, and faster to read. */
    private function value(Document $document, int $id, ?string $payload, Context $context): ?string
    {
        if ($payload !== null && Escaping::isPointer($payload)) {
            return $payload;
        }
        $first = $document->hasChildren($id) ? $document->childIds($id)[0] : null;
        if (($first === null || ($document->tagOf($first) !== 'CONC' && $document->tagOf($first) !== 'CONT')) && ($payload === null || !str_contains($payload, '@'))) {
            return $payload;
        }

        return $context->value($id);
    }

    /** Whether a payload has an alternative other than text: a grammar ("<DATE_VALUE>"), a literal ("Y"). */
    private function typed(Profile $profile, Structure $structure): bool
    {
        foreach ($structure->payload === null ? [] : explode('|', $structure->payload) as $alternative) {
            if ($alternative !== '<NULL>' && (!str_starts_with($alternative, '<') || $profile->type($alternative) !== null)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Structure> every structure of a version, by URI */
    public static function structures(Profile $profile): array
    {
        $found = [];
        $stack = array_map(static fn(\Korin\Gedcom\Spec\Substructure $record): string => $record->uri, array_values($profile->records()));
        while ($stack !== []) {
            $uri = array_pop($stack);
            $structure = $profile->structure($uri);
            if ($structure === null || isset($found[$uri])) {
                continue;
            }
            $found[$uri] = $structure;
            foreach ($structure->substructures as $substructure) {
                $stack[] = $substructure->uri;
            }
        }

        return $found;
    }
}
