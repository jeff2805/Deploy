<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Signs out accounts that are no longer active. Use after "auth".
            'active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            // Restricts an area to one or more roles, e.g. role:resort_owner
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            // Holds an account at the one-time code screen until it has
            // confirmed its email address. Use after "auth".
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Content Security Policy and other security headers on every page.
        // MaintenanceGate closes the public site when the office turns
        // maintenance on in Settings. It exempts the office itself and
        // the sign-in routes, so it cannot lock anybody out.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\MaintenanceGate::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if (! $request->expectsJson()) {
                session()->flash('info', 'Please sign in to continue.');
            }

            return route('login');
        });

        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
