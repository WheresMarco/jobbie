<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An optional extra lock in front of the whole app, on top of the user
 * accounts, for example while testing a server before opening it up. With
 * RADAR_BASIC_AUTH_USER and RADAR_BASIC_AUTH_PASSWORD set, every web
 * request needs those credentials; without them nothing changes.
 */
final class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('radar.basic_auth.user');
        $password = config('radar.basic_auth.password');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            return $next($request);
        }

        if (hash_equals($user, (string) $request->getUser()) && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('', 401, ['WWW-Authenticate' => 'Basic realm="Jobbie", charset="UTF-8"']);
    }

}
