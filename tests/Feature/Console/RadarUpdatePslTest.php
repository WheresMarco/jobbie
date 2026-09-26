<?php

declare(strict_types=1);

use App\Net\PublicSuffixList;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->storage = sys_get_temp_dir().'/radar-storage-'.bin2hex(random_bytes(4));
    $this->app->useStoragePath($this->storage);
});

afterEach(function (): void {
    File::deleteDirectory($this->storage);
});

it('downloads and stores the list', function (): void {
    Http::fake([PublicSuffixList::SOURCE_URL => Http::response(File::get(PublicSuffixList::bundledPath()))]);

    $this->artisan('radar:update-psl')->assertSuccessful();

    expect(File::get(PublicSuffixList::cachedPath()))->toContain('===BEGIN ICANN DOMAINS===')
        ->and(PublicSuffixList::rules()->resolve('jobs.foretag.co.uk')->registrableDomain()->toString())->toBe('foretag.co.uk');
});

it('keeps the old copy when the download is not a suffix list', function (): void {
    File::ensureDirectoryExists(dirname(PublicSuffixList::cachedPath()));
    File::put(PublicSuffixList::cachedPath(), 'old');
    Http::fake([PublicSuffixList::SOURCE_URL => Http::response('<html>maintenance</html>')]);

    $this->artisan('radar:update-psl')->assertFailed();

    expect(File::get(PublicSuffixList::cachedPath()))->toBe('old');
});
