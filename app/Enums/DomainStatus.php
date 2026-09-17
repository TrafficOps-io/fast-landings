<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case PendingPropagation = 'pending_propagation';
    case Active = 'active';
    case Drifted = 'drifted';
    case Unreachable = 'unreachable';
    case Error = 'error';
}
