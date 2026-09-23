<?php

namespace App\Support;

final class BranchOptions
{
    public const VALUES = [
        'CM RECTO',
        'BLU ALUBIJID',
        'BLU TIANO',
        'BAUNGON',
        'BLU TIN-AO',
        'BLU MANOLO',
        'BALINGASAG',
        'BLU SALAY',
        'BLU SUGBONGCOGON',
        'BLU MEDINA',
        'BLU CLAVERIA',
        'BLU MALITBOG',
    ];

    /** @return array<string, string> */
    public static function options(?string ...$legacyValues): array
    {
        $values = self::VALUES;

        foreach ($legacyValues as $legacyValue) {
            if (filled($legacyValue) && ! in_array($legacyValue, $values, true)) {
                $values[] = $legacyValue;
            }
        }

        return array_combine($values, $values);
    }

    /** @return list<string> */
    public static function allowed(?string ...$legacyValues): array
    {
        return array_keys(self::options(...$legacyValues));
    }
}
