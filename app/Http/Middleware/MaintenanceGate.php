<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the public site when the office turns maintenance on.
 *
 * NOT php artisan down. That writes a file, answers every route
 * including the dashboard, and is undone only from a terminal — which
 * is no use to an office that pressed a button in a browser and now
 * cannot get back in to unpress it.
 *
 * THIS CANNOT LOCK ANYONE OUT, and that is the whole design:
 *
 *   - the Municipal Tourism Office keeps the entire site;
 *   - the sign-in, sign-out and verification routes stay open, so a
 *     signed-out administrator can still get back in and switch it
 *     off again;
 *   - the health check at /up stays open, so monitoring does not read
 *     a deliberate closure as a crash.
 *
 * Everyone else gets the 503 page with the office's message.
 */
class MaintenanceGate
{
    /**
     * Route names that must answer even while the site is closed.
     * Prefix match, so "password." covers the whole reset flow.
     *
     * @var list<string>
     */
    private const ALWAYS_OPEN = [
        'login',
        'logout',
        'password.',
        'verification.',
        'verify.',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->get('maintenance.enabled')) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $message = trim((string) $this->settings->get('maintenance.message'));

        /*
         * An empty message is not passed at all. The view falls back
         * with ?? , and '' would satisfy that just as well as a real
         * sentence does — leaving visitors a 503 page with a blank
         * space where the explanation should be.
         */
        return response()->view(
            'errors.503',
            $message === '' ? [] : ['maintenanceMessage' => $message],
            503,
        );
    }

    private function isExempt(Request $request): bool
    {
        $user = $request->user();

        // The office runs the site; closing it to visitors must not
        // close it to the people doing the work.
        if ($user !== null && $this->roleValue($user) === UserRole::SuperAdmin->value) {
            return true;
        }

        if ($request->is('up')) {
            return true;
        }

        $name = (string) $request->route()?->getName();

        foreach (self::ALWAYS_OPEN as $open) {
            if ($name === $open || str_starts_with($name, $open)) {
                return true;
            }
        }

        return false;
    }

    private function roleValue(object $user): string
    {
        return $user->role instanceof UserRole
            ? $user->role->value
            : (string) $user->role;
    }
}
