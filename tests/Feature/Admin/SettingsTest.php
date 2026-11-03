<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settings module.
 *
 * What these guard, in order of how much it would cost to get wrong:
 *
 *   - nobody but the office can read or write any of it;
 *   - an SMTP password never reaches a browser;
 *   - a saved setting actually reaches config, which is the only
 *     reason the rest of the application honours it;
 *   - closing the site for maintenance cannot close it to the office;
 *   - the registry and the forms agree, so a field cannot be declared
 *     and then quietly fail to save.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function office(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /* Who may get in --------------------------------------------------- */

    public function test_the_office_can_open_the_settings_hub(): void
    {
        $this->actingAs($this->office())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Settings');
    }

    public function test_every_declared_group_renders(): void
    {
        $office = $this->office();

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $this->actingAs($office)
                ->get(route('admin.settings.edit', $group))
                ->assertOk();
        }
    }

    public function test_a_tourist_is_refused(): void
    {
        $tourist = User::factory()->create([
            'role' => 'tourist',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($tourist)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($tourist)->get(route('admin.settings.edit', 'general'))->assertForbidden();

        $this->actingAs($tourist)
            ->put(route('admin.settings.update', 'general'), ['general__name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['value' => 'Hijacked']);
    }

    public function test_a_resort_owner_is_refused(): void
    {
        $owner = User::factory()->create([
            'role' => 'resort_owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('admin.settings.index'))->assertForbidden();

        // Was 'payments' until that group was removed. The route is
        // constrained to groups that exist, so a retired name would
        // now answer 404 and the test would stop proving anything
        // about authorisation.
        $this->actingAs($owner)->get(route('admin.settings.edit', 'security'))->assertForbidden();
    }

    /**
     * The payments group is gone and stays gone.
     *
     * It was removed on request. What this really guards is that
     * removing the SCREEN did not remove the POLICY: the deposit
     * percentage and the deadline are still in config and still
     * reachable, because the booking and payment code reads them from
     * there and always did.
     */
    public function test_the_payments_group_is_not_offered(): void
    {
        $this->assertArrayNotHasKey('payments', SettingsRegistry::groups());
        $this->assertArrayNotHasKey('payments', SettingsRegistry::fields());

        foreach (SettingsRegistry::keys() as $key) {
            $this->assertStringStartsNotWith('payments.', $key);
        }

        $html = $this->actingAs($this->office())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Default deposit percentage', $html);
        $this->assertStringNotContainsString('/admin/settings/payments', $html);

        // The route no longer matches, so the old link is a 404.
        $this->actingAs($this->office())->get('/admin/settings/payments')->assertNotFound();

        // And the policy the screen used to edit is untouched.
        $this->assertNotNull(config('visitdasol.payment_policy.deposit_percent'));
        $this->assertNotNull(config('visitdasol.payment_policy.deadline_hours'));
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
    }

    public function test_an_unknown_group_is_not_found(): void
    {
        $this->actingAs($this->office())
            ->get('/admin/settings/not-a-group')
            ->assertNotFound();
    }

    /* Saving ----------------------------------------------------------- */

    public function test_a_saved_setting_is_stored_and_reaches_config(): void
    {
        $this->actingAs($this->office())
            ->put(route('admin.settings.update', 'map'), $this->mapPayload(['map__zoom' => 15]))
            ->assertRedirect(route('admin.settings.edit', 'map'));

        $this->assertDatabaseHas('settings', ['key' => 'map.zoom', 'value' => '15']);

        // The point of the whole module: existing code reading config
        // gets the office's answer without being changed.
        $settings = app(SettingsService::class);
        $settings->flush();
        $settings->applyToConfig();

        $this->assertSame(15, config('visitdasol.map.zoom'));
    }

    public function test_a_value_equal_to_the_default_stores_no_row(): void
    {
        $default = config('visitdasol.map.zoom');

        $this->actingAs($this->office())
            ->put(route('admin.settings.update', 'map'), $this->mapPayload(['map__zoom' => $default]));

        // Storing a copy of the default would make "reset" a feature
        // that has to be built, and the table a list of non-decisions.
        $this->assertDatabaseMissing('settings', ['key' => 'map.zoom']);
    }

    public function test_an_invalid_value_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->office())
            ->from(route('admin.settings.edit', 'map'))
            ->put(route('admin.settings.update', 'map'), $this->mapPayload(['map__zoom' => 99]))
            ->assertSessionHasErrors('map__zoom');

        $this->assertDatabaseMissing('settings', ['key' => 'map.zoom']);
    }

    public function test_unticking_a_switch_saves_as_off(): void
    {
        // The hidden companion input is what makes this work; without
        // it an unticked box sends nothing and the old value survives.
        $this->actingAs($this->office())
            ->put(route('admin.settings.update', 'map'), $this->mapPayload(['map__allow_routing' => 0]));

        $this->assertFalse(app(SettingsService::class)->get('map.allow_routing'));
    }

    public function test_a_change_is_recorded_in_the_activity_log_without_its_value(): void
    {
        $office = $this->office();

        $this->actingAs($office)
            ->put(route('admin.settings.update', 'map'), $this->mapPayload(['map__zoom' => 16]));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $office->id,
            'action' => 'settings.updated',
        ]);

        $log = \App\Models\ActivityLog::query()->where('action', 'settings.updated')->latest('id')->first();

        $this->assertContains('map.zoom', $log->properties['keys']);
        $this->assertSame('map', $log->properties['group']);
    }

    /* Secrets ----------------------------------------------------------- */

    public function test_an_smtp_password_is_never_rendered(): void
    {
        $office = $this->office();

        app(SettingsService::class)->set('email.password', 'super-secret-value', $office);

        $html = $this->actingAs($office)
            ->get(route('admin.settings.edit', 'email'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('super-secret-value', $html);
        // It says one is stored, which is all the screen needs to say.
        $this->assertStringContainsString('A password is saved', $html);
    }

    public function test_an_smtp_password_is_encrypted_at_rest(): void
    {
        app(SettingsService::class)->set('email.password', 'super-secret-value', $this->office());

        $row = Setting::query()->where('key', 'email.password')->first();

        $this->assertNotNull($row);
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString('super-secret-value', (string) $row->value);
        $this->assertSame('super-secret-value', app(SettingsService::class)->get('email.password'));
    }

    public function test_a_blank_password_keeps_the_stored_one(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('email.password', 'keep-me', $this->office());

        $settings->set('email.password', '', $this->office());
        $settings->flush();

        $this->assertSame('keep-me', $settings->get('email.password'));
    }

    /* Maintenance -------------------------------------------------------- */

    public function test_maintenance_closes_the_site_to_visitors_but_not_to_the_office(): void
    {
        $office = $this->office();
        $settings = app(SettingsService::class);

        $settings->set('maintenance.enabled', true, $office);
        $settings->flush();

        // The public is told.
        $this->get('/')->assertStatus(503);

        // The office is not locked out of its own site.
        $this->actingAs($office)->get('/')->assertOk();
        $this->actingAs($office)->get(route('admin.settings.edit', 'maintenance'))->assertOk();

        // And neither is anyone who needs to sign in to help.
        auth()->logout();
        $this->get(route('login'))->assertOk();
    }

    public function test_the_office_message_is_shown_and_a_blank_one_falls_back(): void
    {
        $settings = app(SettingsService::class);
        $office = $this->office();

        $settings->set('maintenance.enabled', true, $office);
        $settings->set('maintenance.message', 'Back on Monday.', $office);
        $settings->flush();

        $this->get('/')->assertStatus(503)->assertSee('Back on Monday.');
    }

    /* The registry itself ------------------------------------------------ */

    public function test_every_field_declares_what_the_form_needs(): void
    {
        foreach (SettingsRegistry::fields() as $group => $fields) {
            $this->assertArrayHasKey($group, SettingsRegistry::groups(),
                "Group {$group} has fields but is not declared in groups().");

            foreach ($fields as $key => $field) {
                $this->assertStringStartsWith($group.'.', $key,
                    "Key {$key} is filed under {$group} but is not prefixed with it.");

                foreach (['type', 'label', 'rules'] as $required) {
                    $this->assertArrayHasKey($required, $field, "{$key} is missing {$required}.");
                }

                if ($field['type'] === 'select') {
                    $this->assertArrayHasKey('options', $field, "{$key} is a select with no options.");
                }
            }
        }
    }

    /**
     * A config path that does not exist means a default of null, and a
     * field that silently has no default. This caught four wrong paths
     * when the registry was written.
     */
    public function test_every_config_path_a_field_names_exists(): void
    {
        foreach (SettingsRegistry::fields() as $fields) {
            foreach ($fields as $key => $field) {
                if (empty($field['config'])) {
                    continue;
                }

                $this->assertNotSame(
                    '__missing__',
                    config($field['config'], '__missing__'),
                    "{$key} points at config path {$field['config']}, which does not exist.",
                );
            }
        }
    }

    /** Every field without a config path needs a default of its own. */
    public function test_every_field_without_a_config_path_has_a_default(): void
    {
        $defaults = SettingsRegistry::defaults();

        foreach (SettingsRegistry::fields() as $fields) {
            foreach ($fields as $key => $field) {
                if (! empty($field['config'])) {
                    continue;
                }

                $this->assertArrayHasKey($key, $defaults,
                    "{$key} has no config path and no default, so it starts as null.");
            }
        }
    }

    /**
     * The map form posts every field at once, so a test that changes
     * one still has to send the rest or the required rules fail.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mapPayload(array $overrides = []): array
    {
        $settings = app(SettingsService::class);
        $payload = [];

        foreach (SettingsRegistry::fieldsIn('map') as $key => $field) {
            $name = str_replace('.', '__', $key);
            $value = $settings->get($key);

            $payload[$name] = $field['type'] === 'bool' ? (int) $value : $value;
        }

        return array_merge($payload, $overrides);
    }
}
