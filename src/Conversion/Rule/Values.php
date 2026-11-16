<?php

declare(strict_types=1);

namespace Korin\Gedcom\Conversion\Rule;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Spec\Profile;
use Korin\Gedcom\Spec\Structure;
use Korin\Gedcom\Syntax\Document;
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

    /** @param array<string, array<string, string>> $spellings by target element ("<MULTIMEDIA_FORMAT>"): source value (lowercase) => target value */
    public function __construct(
        private readonly Version $from,
        private readonly Version $to,
        private readonly array $spellings = [],
    ) {}

    public function needsPrepass(): bool
    {
        return false;
    }

    /** The tags of the structures with an enumeration or a pointer in either version. */
    public function tags(): array
    {
        if ($this->tags === null) {
            $tags = [];
            foreach ([Profile::for($this->from), Profile::for($this->to)] as $profile) {
                foreach (self::structures($profile) as $structure) {
                    if (Payloads::enumerated($profile, $structure) !== null || $structure->pointerTarget() !== null) {
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
        $source = $context->sourceStructure($id);
        $tags = [$document->tagOf($id)];
        for ($above = $parent; $above !== 0; $above = $document->parentOf($above)) {
            array_unshift($tags, $document->tagOf($above));
        }
        $profile = Profile::for($this->to);
        $target = $profile->resolve($tags);
        $payload = $document->payloadOf($id);
        if ($source === null || $target === null || Payloads::fits($profile, $target, $payload)
            || !Payloads::fits(Profile::for($this->from), $source, $payload) || ($payload === 'Y' && $target->payload === null)) {
            return true;
        }
        $spelling = $this->spellings[(string) Payloads::enumerated($profile, $target)][strtolower((string) $payload)] ?? null;
        if ($spelling !== null) {
            $document->setPayload($id, $spelling);

            return true;
        }
        $tag = $document->tagOf($id);

        return $context->unsupported($id, 'KL3001', $payload === null ? "{$this->to->value} {$tag} needs a value" : "{$this->to->value} {$tag} has no such value");
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
