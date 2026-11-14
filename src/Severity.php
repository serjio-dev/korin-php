<?php

declare(strict_types=1);

namespace Korin\Gedcom;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
