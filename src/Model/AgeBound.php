<?php

declare(strict_types=1);

namespace Korin\Gedcom\Model;

/** "<" or ">" before an age: younger or older than the duration. */
enum AgeBound: string
{
    case Less = '<';
    case Greater = '>';
}
