<?php

namespace App\Enums;

enum DomainKind: string
{
    case System = 'system';
    case Custom = 'custom';
}
