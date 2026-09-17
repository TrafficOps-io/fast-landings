<?php

namespace TrafficOps\FastLandings\Ui\Support;

class Brand
{
    public static function mark(string $brand): string
    {
        $brand = match ($brand) {
            'pwapps', 'hookroute', 'fastlandings' => $brand,
            default => 'trafficops',
        };

        return file_get_contents(__DIR__.'/../../resources/brand/'.$brand.'.svg');
    }
}
