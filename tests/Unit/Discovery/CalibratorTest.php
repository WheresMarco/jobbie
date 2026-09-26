<?php

declare(strict_types=1);

use App\Discovery\Calibration\Calibrator;

it('collects distinct asset hosts from the HTML', function (): void {
    expect(Calibrator::assetHosts($this->fixture('http/home.html')))->toBe([
        'assets.teamtailor-cdn.com',
        'images.teamtailor-cdn.com',
        'scripts.teamtailor-cdn.com',
        'www.googletagmanager.com',
        'www.youtube.com',
    ])->and(Calibrator::assetHosts(''))->toBe([]);
});
