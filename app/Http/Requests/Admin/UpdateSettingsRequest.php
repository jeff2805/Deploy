<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use App\Settings\SettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates one group of settings against the registry.
 *
 * THE RULES ARE NOT WRITTEN HERE. They are read from the field
 * definitions, so a form can only ever submit keys the registry
 * declares and can only ever pass values those rules allow. A posted
 * field the registry does not know is not validated and not saved; it
 * is simply absent from the data the controller receives.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateGroup', [Setting::class, $this->group()]) ?? false;
    }

    public function group(): string
    {
        return (string) $this->route('group');
    }

    /**
     * An unticked checkbox sends nothing at all, which a boolean rule
     * reads as "absent" rather than "false". Every declared boolean is
     * filled in before validation so that unticking one saves.
     */
    protected function prepareForValidation(): void
    {
        $fill = [];

        foreach (SettingsRegistry::fieldsIn($this->group()) as $key => $field) {
            if ($field['type'] !== 'bool') {
                continue;
            }

            $fill[$this->inputName($key)] = $this->boolean($this->inputName($key));
        }

        if ($fill !== []) {
            $this->merge($fill);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];

        foreach (SettingsRegistry::fieldsIn($this->group()) as $key => $field) {
            $rules[$this->inputName($key)] = $field['rules'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $names = [];

        foreach (SettingsRegistry::fieldsIn($this->group()) as $key => $field) {
            $names[$this->inputName($key)] = strtolower($field['label']);
        }

        return $names;
    }

    /**
     * The validated values, back under their real dotted keys.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();
        $out = [];

        foreach (SettingsRegistry::fieldsIn($this->group()) as $key => $field) {
            $name = $this->inputName($key);

            if (array_key_exists($name, $validated)) {
                $out[$key] = $validated[$name];
            }
        }

        return $out;
    }

    /**
     * A dotted key is array syntax in an HTML form name, so "map.zoom"
     * would arrive as $input['map']['zoom'] and every rule would miss
     * it. The input name uses a double underscore instead.
     */
    public function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
