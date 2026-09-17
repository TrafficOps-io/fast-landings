<?php

namespace App\Enums;

enum DomainProvider: string
{
    case System = 'system';
    case Dns = 'dns';
    case Cloudflare = 'cloudflare';
}
