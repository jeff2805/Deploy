<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\SettingsService;
use App\Settings\SettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * The settings area of the Municipal Tourism Office dashboard.
 *
 * It renders whatever SettingsRegistry declares. There is no method
 * per group and no view per group, because a group is data rather
 * than code — which is why adding one is an entry in the registry and
 * nothing else.
 *
 * WHAT IT DOES NOT DO. User accounts, activity logs, destinations,
 * announcements and the profile screen all already exist and work;
 * the hub links to them rather than growing a second copy. Nothing in
 * this controller writes to a user, a resort or a booking.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
    ) {}

    /** The hub: the groups this person may open, and links to the rest. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Setting::class);

        /** @var \App\Policies\SettingPolicy $policy */
        $policy = app(\App\Policies\SettingPolicy::class);

        return view('admin.settings.index', [
            'groups' => $policy->groupsFor($request->user()),
            'canOperate' => $request->user()->can('operate', Setting::class),
        ]);
    }

    /** One group's form. */
    public function edit(Request $request, string $group): View
    {
        abort_unless(SettingsRegistry::hasGroup($group), 404);
        $this->authorize('viewGroup', [Setting::class, $group]);

        return view('admin.settings.group', [
            'group' => $group,
            'definition' => SettingsRegistry::groups()[$group],
            'fields' => SettingsRegistry::fieldsIn($group),
            'values' => $this->settings->group($group),
            'settings' => $this->settings,
            'canOperate' => $request->user()->can('operate', Setting::class),
        ]);
    }

    /** Save one group. */
    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        abort_unless(SettingsRegistry::hasGroup($group), 404);

        $changed = $this->settings->setGroup($group, $request->settings(), $request->user());

        if ($changed !== []) {
            /*
             * The audit trail records WHICH settings moved, never what
             * they moved to: one of them is an SMTP password, and the
             * activity log is read by people who should not see it.
             * No subject is attached because a settings change is not a
             * change to a record.
             */
            $this->logger->log(
                'settings.updated',
                null,
                'Updated the '.SettingsRegistry::groups()[$group]['label'].' settings',
                ['group' => $group, 'keys' => $changed],
            );
        }

        return redirect()
            ->route('admin.settings.edit', $group)
            ->with('status', $changed === []
                ? 'Nothing changed.'
                : count($changed).' setting'.(count($changed) === 1 ? '' : 's').' saved.');
    }

    /**
     * Send one message to the signed-in administrator, to prove the
     * SMTP settings work.
     *
     * It goes to their own address and nowhere else: a test that lets
     * you type a recipient is a way to send mail from the municipality
     * to anybody.
     */
    public function testMail(Request $request): RedirectResponse
    {
        $this->authorize('updateGroup', [Setting::class, 'email']);

        $to = $request->user()->email;

        try {
            Mail::raw(
                "This is a test message from VisitDasol.\n\n"
                ."If you are reading it, the SMTP settings are working.",
                fn ($message) => $message->to($to)->subject('VisitDasol test message'),
            );
        } catch (Throwable $e) {
            /*
             * The exception can carry the host and the username. It
             * cannot carry the password, but it is still shown only to
             * the administrator who pressed the button, and it is not
             * written to the activity log.
             */
            return back()->with('error', 'The test message could not be sent: '.$e->getMessage());
        }

        $this->logger->log('settings.mail_tested', null, 'Sent an SMTP test message');

        return back()->with('status', 'A test message was sent to '.$to.'.');
    }

    /**
     * Clear the caches.
     *
     * config:clear is NOT among these. This installation runs without
     * a cached config file, and clearing one that does not exist is
     * harmless — but `optimize:clear` would also wipe the compiled
     * views mid-request on a live site. These three are the safe ones.
     */
    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorize('operate', Setting::class);

        $ran = [];

        foreach (['cache:clear', 'view:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
                $ran[] = $command;
            } catch (Throwable) {
                // One failing must not stop the others.
            }
        }

        $this->settings->flush();

        $this->logger->log('settings.cache_cleared', null, 'Cleared the application caches', ['commands' => $ran]);

        return back()->with('status', 'Cleared: '.implode(', ', $ran).'.');
    }
}
