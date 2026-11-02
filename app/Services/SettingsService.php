<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reads and writes the stored settings.
 *
 * ONE QUERY PER REQUEST AT MOST, and usually none: every row is
 * loaded together and kept in the cache until a save clears it. A
 * settings module that costs a query per lookup would be felt on
 * every page of the site, because the config override below runs on
 * every request.
 *
 * IT MUST NEVER TAKE THE SITE DOWN. The table may not exist — during
 * the first migrate, during tests that build the schema step by step,
 * on a clone that has not migrated yet — and the database may be
 * unreachable. In every one of those cases this returns the config
 * defaults and the site runs exactly as it did before the module was
 * added. That is what the try/catch around the load is for, and it is
 * deliberate rather than defensive habit.
 */
class SettingsService
{
    private const CACHE_KEY = 'visitdasol.settings';

    /** @var array<string, string|null>|null */
    private ?array $loaded = null;

    /**
     * The config values as the FILES declare them, captured just
     * before applyToConfig() overwrites any of them.
     *
     * Without this the module cannot tell a default from a stored
     * value: applyToConfig() runs at boot, so by the time anything
     * asks config('visitdasol.map.zoom') the answer is already the
     * office's choice rather than the file's. "Is this the default?"
     * would then always be "yes", and the row that says otherwise
     * would never be cleaned up.
     *
     * @var array<string, mixed>
     */
    private array $fileDefaults = [];

    /**
     * The value for one key, cast to the type the registry declares.
     *
     * Order: the stored row, then the config default, then the
     * registry's own default.
     */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $field = SettingsRegistry::field($key);

        if ($field === null) {
            return $fallback;
        }

        $rows = $this->rows();

        if (array_key_exists($key, $rows)) {
            return $this->cast($rows[$key], $field, $key);
        }

        return $fallback ?? $this->defaultFor($key, $field);
    }

    /**
     * Every value in one group, ready for a form.
     *
     * Secrets come back as null, never as the stored value: this is
     * what the edit screen renders, and an SMTP password must not
     * travel to a browser even once.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $out = [];

        foreach (SettingsRegistry::fieldsIn($group) as $key => $field) {
            $out[$key] = $field['type'] === 'secret' ? null : $this->get($key);
        }

        return $out;
    }

    /** Whether a secret has something stored, without revealing it. */
    public function hasSecret(string $key): bool
    {
        $rows = $this->rows();

        return isset($rows[$key]) && $rows[$key] !== '';
    }

    /**
     * Save one value.
     *
     * A value equal to the default removes the row rather than storing
     * a copy of it, so "reset to default" needs no separate feature and
     * the table stays a list of genuine decisions.
     */
    public function set(string $key, mixed $value, ?User $actor = null): void
    {
        $field = SettingsRegistry::field($key);

        if ($field === null) {
            // Never write a key the registry has not declared.
            return;
        }

        $encrypted = $field['type'] === 'secret';

        if ($encrypted && ($value === null || $value === '')) {
            return;   // blank means "keep what is there"
        }

        if (! $encrypted && $this->isDefault($key, $field, $value)) {
            Setting::query()->where('key', $key)->delete();
            $this->flush();

            return;
        }

        $stored = $encrypted
            ? Crypt::encryptString((string) $value)
            : $this->toText($value, $field);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $stored,
                'is_encrypted' => $encrypted,
                'updated_by' => $actor?->id,
            ],
        );

        $this->flush();
    }

    /**
     * Save a whole group and report what actually changed.
     *
     * The returned list is what the audit trail records. Secrets are
     * named but never valued.
     *
     * @param array<string, mixed> $values
     * @return list<string> the keys whose value moved
     */
    public function setGroup(string $group, array $values, ?User $actor = null): array
    {
        $changed = [];

        foreach (SettingsRegistry::fieldsIn($group) as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $new = $values[$key];

            if ($field['type'] === 'secret') {
                if ($new !== null && $new !== '') {
                    $this->set($key, $new, $actor);
                    $changed[] = $key;
                }

                continue;
            }

            $before = $this->get($key);

            $this->set($key, $new, $actor);

            if ($this->get($key) !== $before) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Push every stored value over its config default.
     *
     * Called once per request from SettingsServiceProvider. This is
     * what lets existing code go on reading config('visitdasol.map.zoom')
     * and quietly get the office's answer instead of the file's.
     */
    public function applyToConfig(): void
    {
        $rows = $this->rows();

        if ($rows === []) {
            return;
        }

        foreach ($rows as $key => $raw) {
            $field = SettingsRegistry::field($key);

            if ($field === null || empty($field['config'])) {
                continue;
            }

            // Remember what the file said before taking it away.
            if (! array_key_exists($field['config'], $this->fileDefaults)) {
                $this->fileDefaults[$field['config']] = config($field['config']);
            }

            config([$field['config'] => $this->cast($raw, $field, $key)]);
        }
    }

    /** Drop the cache. Public because a seeder or test may need it. */
    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /* Internals ---------------------------------------------------------- */

    /**
     * Every stored row as key => raw text, decrypted where flagged.
     *
     * @return array<string, string|null>
     */
    private function rows(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $this->loaded = Cache::rememberForever(self::CACHE_KEY, function (): array {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                $out = [];

                foreach (Setting::query()->get(['key', 'value', 'is_encrypted']) as $row) {
                    if ($row->is_encrypted) {
                        try {
                            $out[$row->key] = Crypt::decryptString((string) $row->value);
                        } catch (Throwable) {
                            // A key rotated out from under the value.
                            // Treat it as unset rather than crash.
                            $out[$row->key] = null;
                        }

                        continue;
                    }

                    $out[$row->key] = $row->value;
                }

                return $out;
            });
        } catch (Throwable) {
            // No database, no table, no cache store. The site keeps its
            // config defaults and carries on.
            $this->loaded = [];
        }

        return $this->loaded;
    }

    private function defaultFor(string $key, array $field): mixed
    {
        if (! empty($field['config'])) {
            /*
             * The file's value, not the live one. applyToConfig() has
             * usually already replaced the live value with the stored
             * setting, and asking config() here would make every stored
             * value look like the default it replaced.
             */
            $path = $field['config'];

            $value = array_key_exists($path, $this->fileDefaults)
                ? $this->fileDefaults[$path]
                : config($path);

            if ($value !== null) {
                return $this->castNative($value, $field);
            }
        }

        return SettingsRegistry::defaults()[$key] ?? null;
    }

    private function isDefault(string $key, array $field, mixed $value): bool
    {
        $default = $this->defaultFor($key, $field);

        return $this->castNative($value, $field) === $default;
    }

    /** Text out of the database into the declared type. */
    private function cast(?string $raw, array $field, string $key): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($field['type']) {
            'bool' => $raw === '1',
            'number' => $this->numberFrom($raw),
            default => $raw,
        };
    }

    /** A value of any origin into the declared type. */
    private function castNative(mixed $value, array $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field['type']) {
            'bool' => (bool) $value,
            'number' => $this->numberFrom((string) $value),
            default => (string) $value,
        };
    }

    private function numberFrom(string $raw): int|float
    {
        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    private function toText(mixed $value, array $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field['type']) {
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
