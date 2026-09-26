<?php

declare(strict_types=1);

namespace App\Console\Concerns;

trait Translates
{
    /**
     * Translated console line. Falls back to the key if it is missing.
     *
     * @param  array<string, string|int>  $replace
     */
    protected function t(string $key, array $replace = []): string
    {
        $line = __($key, array_map(strval(...), $replace));

        return is_string($line) ? $line : $key;
    }
}
