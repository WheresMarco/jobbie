<?php

declare(strict_types=1);

namespace App\Net;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pdp\Rules;

/**
 * Locates the Public Suffix List. radar:update-psl keeps a fresh copy in
 * storage; the copy bundled in resources/psl is the fallback.
 */
final class PublicSuffixList
{
    public const string SOURCE_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    public static function cachedPath(): string
    {
        return storage_path('app/psl/public_suffix_list.dat');
    }

    public static function bundledPath(): string
    {
        return resource_path('psl/public_suffix_list.dat');
    }

    public static function rules(): Rules
    {
        return Rules::fromPath(File::exists(self::cachedPath()) ? self::cachedPath() : self::bundledPath());
    }

    /**
     * Download the list, validate it and replace the cached copy.
     *
     * @return int Number of bytes written.
     */
    public static function update(string $userAgent, int $timeout): int
    {
        $content = Http::withUserAgent($userAgent)
            ->timeout($timeout)
            ->retry(3, 1000)
            ->get(self::SOURCE_URL)
            ->throw()
            ->body();

        // Throws on content that is not a suffix list.
        Rules::fromString($content);
        if (! str_contains($content, '===BEGIN ICANN DOMAINS===')) {
            throw new \RuntimeException('Downloaded file is not a Public Suffix List');
        }

        File::ensureDirectoryExists(dirname(self::cachedPath()));
        File::put(self::cachedPath().'.tmp', $content);
        File::move(self::cachedPath().'.tmp', self::cachedPath());

        return strlen($content);
    }
}
