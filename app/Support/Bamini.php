<?php

namespace App\Support;

class Bamini
{
    public static function toUnicode(string $raw): string
    {
        static $keys;
        if ($keys === null) {
            // Share the exact glyph assignments with the browser converter.
            $mapping = json_decode(file_get_contents(resource_path('data/bamini.json')), true, 512, JSON_THROW_ON_ERROR);
            uksort($mapping, fn ($a, $b) => strlen($b) <=> strlen($a));
            $keys = [];
            foreach ($mapping as $key => $value) {
                $keys[$key[0]][$key] = $value;
            }
        }

        $result = '';
        for ($offset = 0, $length = strlen($raw); $offset < $length;) {
            foreach ($keys[$raw[$offset]] ?? [] as $key => $value) {
                if (substr_compare($raw, $key, $offset, strlen($key)) !== 0) {
                    continue;
                }
                $next = $raw[$offset + strlen($key)] ?? '';
                if ((str_starts_with($key, 'n') || $key === 'xs') && str_ends_with($key, 's') && preg_match('/[hpP;{_+]/', $next)) {
                    continue;
                }
                if (str_ends_with($key, 'h') && preg_match('/[pP;]/', $next)) {
                    continue;
                }
                $result .= $value;
                $offset += strlen($key);

                continue 2;
            }
            $result .= $raw[$offset++];
        }

        return $result;
    }

    public static function searchTerms(string $query): array
    {
        if (! config('features.bamini')) {
            return [$query];
        }
        $converted = self::toUnicode($query);

        return $converted !== $query && preg_match('/\p{Tamil}/u', $converted) ? [$query, $converted] : [$query];
    }
}
