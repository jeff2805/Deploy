@extends('layouts.admin')

@section('title', $definition['label'].' settings')

@push('styles')
    <link rel="stylesheet" href="@vdasset('css/settings.css')">
@endpush

@section('content')
    <x-page-header :title="$definition['label']" :subtitle="$definition['blurb']" />

    <p class="vd-settings__back">
        <a href="{{ route('admin.settings.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> All settings
        </a>
    </p>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <p class="mb-1 fw-semibold">Nothing was saved. Please check the fields below.</p>
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.settings.update', $group) }}" class="vd-panel vd-settings__form">
        @csrf
        @method('PUT')

        @foreach ($fields as $key => $field)
            @php
                // A dotted key is array syntax in a form name, so the
                // input uses double underscores. See UpdateSettingsRequest.
                $name = str_replace('.', '__', $key);
                $id = 'set-'.$name;
                $value = old($name, $values[$key] ?? null);
                $invalid = $errors->has($name);
            @endphp

            <div @class(['vd-settings__field', 'is-invalid' => $invalid])>
                @if ($field['type'] === 'bool')
                    <div class="form-check">
                        {{-- The hidden input is what makes unticking save. --}}
                        <input type="hidden" name="{{ $name }}" value="0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="{{ $id }}" name="{{ $name }}" value="1"
                               @checked((bool) $value)>
                        <label class="form-check-label fw-semibold" for="{{ $id }}">
                            {{ $field['label'] }}
                        </label>
                    </div>
                @else
                    <label class="form-label fw-semibold" for="{{ $id }}">{{ $field['label'] }}</label>

                    @if ($field['type'] === 'textarea')
                        <textarea class="form-control" id="{{ $id }}" name="{{ $name }}"
                                  rows="3">{{ $value }}</textarea>

                    @elseif ($field['type'] === 'select')
                        <select class="form-select" id="{{ $id }}" name="{{ $name }}">
                            @foreach ($field['options'] as $option => $label)
                                <option value="{{ $option }}" @selected((string) $value === (string) $option)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>

                    @elseif ($field['type'] === 'secret')
                        {{--
                            Never rendered with a value. The service
                            returns null for secrets, and a blank box
                            means "keep the stored one" rather than
                            "erase it".
                        --}}
                        <input class="form-control" type="password" id="{{ $id }}" name="{{ $name }}"
                               value="" autocomplete="new-password"
                               placeholder="{{ $settings->hasSecret($key) ? 'A password is saved. Type to replace it.' : 'Not set' }}">

                    @elseif ($field['type'] === 'color')
                        <span class="vd-settings__colour">
                            <input class="form-control form-control-color" type="color"
                                   id="{{ $id }}" name="{{ $name }}" value="{{ $value }}">
                            <code>{{ $value }}</code>
                        </span>

                    @else
                        <input class="form-control"
                               type="{{ in_array($field['type'], ['email', 'number', 'url'], true) ? $field['type'] : 'text' }}"
                               @if (isset($field['step'])) step="{{ $field['step'] }}" @endif
                               id="{{ $id }}" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endif

                @if (! empty($field['help']))
                    <p class="vd-settings__help">{{ $field['help'] }}</p>
                @endif

                @error($name)
                    <p class="vd-settings__error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        <div class="vd-settings__actions">
            <button type="submit" class="btn btn-primary">Save {{ strtolower($definition['label']) }}</button>
            <a class="btn btn-light border" href="{{ route('admin.settings.index') }}">Cancel</a>
        </div>
    </form>

    @if ($group === 'email' && $canOperate)
        <section class="vd-panel vd-settings__ops">
            <div>
                <p class="vd-settings__title">Send a test message</p>
                <p class="vd-settings__blurb mb-0">
                    Sends one message to your own address, {{ auth()->user()->email }}, using the
                    settings above. Save first — the test uses what is stored, not what is typed.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.settings.mail-test') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary">Send test</button>
            </form>
        </section>
    @endif

    @if ($group === 'maintenance')
        <p class="vd-settings__note">
            Closing the site affects visitors only. You, and anyone else signed in to
            the office dashboard, keep full access, and the sign-in page stays open —
            so this cannot lock you out of your own site.
        </p>
    @endif
@endsection
