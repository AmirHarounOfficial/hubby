<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA authenticates with Bearer tokens (Sanctum personal access
        // tokens), NOT cookie/session SPA auth — so we intentionally do NOT
        // enable statefulApi(). Enabling it would apply the web middleware
        // (session + CSRF) to API routes and reject every browser mutation
        // with a 419 CSRF error.
        $middleware->alias([
            'org.member' => \App\Http\Middleware\EnsureOrganizationMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API requests should always get a JSON 401 when unauthenticated, never
        // a redirect to a (non-existent) "login" named route — which otherwise
        // surfaces as a confusing 500. The frontend's axios interceptor turns a
        // 401 into a clean logout + redirect to the login page.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
