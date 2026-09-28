<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The radar is for personal use and has no user accounts. With
 * RADAR_BASIC_AUTH_USER and RADAR_BASIC_AUTH_PASSWORD set, every web
 * request needs those credentials. Without them the app is only served
 * outside production.
 */
final class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('radar.basic_auth.user');
        $password = config('radar.basic_auth.password');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            abort_if(app()->environment('production'), 403, 'Set RADAR_BASIC_AUTH_USER and RADAR_BASIC_AUTH_PASSWORD.');

            return $next($request);
        }

        if (hash_equals($user, (string) $request->getUser()) && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('', 401, ['WWW-Authenticate' => 'Basic realm="Jobbie", charset="UTF-8"']);
    }

    public static function configured(): bool
    {
        return is_string(config('radar.basic_auth.user')) && config('radar.basic_auth.user') !== ''
            && is_string(config('radar.basic_auth.password')) && config('radar.basic_auth.password') !== '';
    }
}
