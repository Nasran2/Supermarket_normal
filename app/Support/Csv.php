<?php

namespace App\Support;

final class Csv
{
    /** Keep untrusted text from becoming a formula when the export is opened. */
    public static function row(array $cells): array
    {
        return array_map(function ($value) {
            if (is_string($value) && preg_match('/^[\x00-\x20]*(?:[=+@\-\t\r\n]|＝|＋|－|＠)/', $value)) {
                return "'".$value;
            }

            return $value;
        }, $cells);
    }
}
