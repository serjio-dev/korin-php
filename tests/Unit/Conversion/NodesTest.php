<?php

declare(strict_types=1);

namespace Korin\Gedcom\Tests\Unit\Conversion;

use Korin\Gedcom\Conversion\Context;
use Korin\Gedcom\Conversion\NodeRule;
use Korin\Gedcom\Conversion\Rule\Nodes;
use Korin\Gedcom\ConvertOptions;
use Korin\Gedcom\Gedcom;
use Korin\Gedcom\Syntax\Document;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NodesTest extends TestCase
{
    #[Test]
    public function everyStructureOnceInDocumentOrderAndNothingARuleRemoved(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 @I1@ INDI\n1 NAME A\n2 CONC b\n1 _OLD x\n2 DATE 1900\n1 BIRT\n2 DATE 1901\n0 TRLR\n");
        $seen = [];
        $remove = $this->rule(static function (Document $d, int $id) use (&$seen): bool {
            $seen[] = 'remove:' . $d->tagOf($id);
            if ($d->tagOf($id) !== '_OLD') {
                return true;
            }
            $d->removeNode($id);

            return false;
        });
        $next = $this->rule(static function (Document $d, int $id) use (&$seen): bool {
            $seen[] = 'next:' . $d->tagOf($id);

            return true;
        });
        $indi = $doc->childIds(0)[1];

        (new Nodes($remove, $next))->record($doc, $indi, new Context($doc, $doc, new ConvertOptions(), null));

        self::assertSame([
            'remove:INDI', 'next:INDI', 'remove:NAME', 'next:NAME', 'remove:_OLD',
            'remove:BIRT', 'next:BIRT', 'remove:DATE', 'next:DATE',
        ], $seen);
    }

    #[Test]
    public function anExtensionARuleMakesAStructureAgainGoesToTheRulesOfItsTagItHasNotBeenGiven(): void
    {
        $doc = Gedcom::parse("0 HEAD\n0 @I1@ INDI\n1 _BIRT\n2 DATE 1900\n0 TRLR\n");
        $seen = [];
        $birt = $this->rule(static function (Document $d, int $id) use (&$seen): bool {
            $seen[] = 'birt:' . $d->tagOf($id);

            return true;
        }, ['BIRT']);
        $restore = $this->rule(static function (Document $d, int $id) use (&$seen): bool {
            $seen[] = 'restore:' . $d->tagOf($id);
            $d->setTag($id, 'BIRT');

            return true;
        }, ['_BIRT']);
        $any = $this->rule(static function (Document $d, int $id) use (&$seen): bool {
            $seen[] = 'any:' . $d->tagOf($id);

            return true;
        });

        (new Nodes($birt, $restore, $any))->record($doc, $doc->childIds(0)[1], new Context($doc, $doc, new ConvertOptions(), null));

        self::assertSame(['any:INDI', 'restore:_BIRT', 'birt:BIRT', 'any:BIRT', 'any:DATE'], $seen);
    }

    /**
     * @param \Closure(Document, int): bool $apply
     * @param ?list<string> $tags
     */
    private function rule(\Closure $apply, ?array $tags = null): NodeRule
    {
        return new class ($apply, $tags) implements NodeRule {
            /**
             * @param \Closure(Document, int): bool $apply
             * @param ?list<string> $tags
             */
            public function __construct(private readonly \Closure $apply, private readonly ?array $tags) {}

            public function needsPrepass(): bool
            {
                return false;
            }

            public function tags(): ?array
            {
                return $this->tags;
            }

            public function node(Document $document, int $id, Context $context): bool
            {
                return ($this->apply)($document, $id);
            }
        };
    }
}
