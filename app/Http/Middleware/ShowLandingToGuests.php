<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Platform;
use App\Livewire\Register;
use App\Livewire\ResumeMatches;
use App\Stats\Coverage;
use App\Stats\RadarStats;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the landing page on / to guests instead of sending them to the
 * login page. Signed-in users get the job list on the same URL.
 */
final readonly class ShowLandingToGuests
{
    public function __construct(private RadarStats $stats)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guest()) {
            return $next($request);
        }

        return response()->view('landing', [
            'coverage' => Coverage::cached($this->stats),
            'platforms' => array_map(self::source(...), Platform::cases()),
            'registration' => Register::enabled(),
            'matching' => ResumeMatches::enabled(),
        ]);
    }

    /**
     * A source's name and logo, from public/images/sources. Without a logo
     * file the name is shown as text.
     *
     * @return array{key: string, name: string, logo: string|null}
     */
    private static function source(Platform $platform): array
    {
        $logo = null;
        foreach (['svg', 'png'] as $extension) {
            if (is_file(public_path($path = "images/sources/{$platform->value}.{$extension}"))) {
                $logo = asset($path);
                break;
            }
        }

        return ['key' => $platform->value, 'name' => __("radar.platforms.{$platform->value}"), 'logo' => $logo];
    }
}
