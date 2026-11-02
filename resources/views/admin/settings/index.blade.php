@extends('layouts.admin')

@section('title', 'Settings')

@push('styles')
    <link rel="stylesheet" href="@vdasset('css/settings.css')">
@endpush

@section('content')
    <x-page-header title="Settings"
                   subtitle="What the site calls itself, how it reaches people, and the rules it works by. A setting left alone keeps the value it shipped with." />

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif

    <div class="row g-4">
        @foreach ($groups as $key => $definition)
            <div class="col-md-6 col-xl-4">
                <a class="vd-settings__card" href="{{ route('admin.settings.edit', $key) }}">
                    <span class="vd-settings__icon">
                        <i class="bi bi-{{ $definition['icon'] }}" aria-hidden="true"></i>
                    </span>
                    <span class="vd-settings__body">
                        <span class="vd-settings__title">{{ $definition['label'] }}</span>
                        <span class="vd-settings__blurb">{{ $definition['blurb'] }}</span>
                    </span>
                    <i class="bi bi-chevron-right vd-settings__chev" aria-hidden="true"></i>
                </a>
            </div>
        @endforeach
    </div>

    {{--
        The rest of the brief's settings list is not missing: it is
        already built, and it is linked rather than copied. A second
        user table on this page would be a second place for the two to
        disagree.
    --}}
    <h2 class="vd-settings__heading">Managed on their own screens</h2>
    <p class="vd-settings__note">
        These were part of VisitDasol before this page existed and are unchanged.
        They are listed here so the settings area is a complete index of what the
        office can control.
    </p>

    <div class="row g-4">
        @foreach ([
            ['route' => 'admin.users.index', 'icon' => 'people', 'title' => 'User accounts',
             'blurb' => 'Search, filter, activate and deactivate accounts.'],
            ['route' => 'admin.owner-applications.index', 'icon' => 'person-check', 'title' => 'Owner applications',
             'blurb' => 'Approve or refuse the people asking to list a resort.'],
            ['route' => 'admin.resort-applications.index', 'icon' => 'building-check', 'title' => 'Resort applications',
             'blurb' => 'Review a listing before the public can see it.'],
            ['route' => 'admin.destination-categories.index', 'icon' => 'tags', 'title' => 'Destination categories',
             'blurb' => 'The categories visitors filter destinations by.'],
            ['route' => 'admin.announcements.index', 'icon' => 'megaphone', 'title' => 'Announcements',
             'blurb' => 'What the public sees on the home page.'],
            ['route' => 'admin.activity-logs.index', 'icon' => 'clock-history', 'title' => 'Activity log',
             'blurb' => 'Every recorded action, including changes made here.'],
            ['route' => 'profile.edit', 'icon' => 'person-circle', 'title' => 'Your account',
             'blurb' => 'Your name, email, photograph and password.'],
        ] as $link)
            @if (Route::has($link['route']))
                <div class="col-md-6 col-xl-4">
                    <a class="vd-settings__card vd-settings__card--quiet" href="{{ route($link['route']) }}">
                        <span class="vd-settings__icon vd-settings__icon--quiet">
                            <i class="bi bi-{{ $link['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <span class="vd-settings__body">
                            <span class="vd-settings__title">{{ $link['title'] }}</span>
                            <span class="vd-settings__blurb">{{ $link['blurb'] }}</span>
                        </span>
                        <i class="bi bi-chevron-right vd-settings__chev" aria-hidden="true"></i>
                    </a>
                </div>
            @endif
        @endforeach
    </div>

    {{--
        Roles are shown and not edited. They are an enum in the source,
        not rows in a table, so a screen that let you add one would be
        promising something it cannot deliver.
    --}}
    <h2 class="vd-settings__heading">Roles</h2>
    <p class="vd-settings__note">
        VisitDasol has three roles. They are defined in the application rather than
        the database, so they are listed here for reference and changed by a
        developer. Every permission in the site is decided server side from this
        value.
    </p>

    <section class="vd-panel">
        <x-scroll-region label="Roles">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Stored as</th>
                        <th scope="col">What it may do</th>
                        <th scope="col">Settings access</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Enums\UserRole::cases() as $role)
                        <tr>
                            <td class="fw-semibold">{{ $role->label() }}</td>
                            <td><code>{{ $role->value }}</code></td>
                            <td>{{ $role->description() }}</td>
                            <td>
                                @php
                                    $allowed = collect(\App\Settings\SettingsRegistry::groups())
                                        ->filter(fn ($g) => in_array($role->value, $g['roles'], true))
                                        ->count();
                                @endphp
                                {{ $allowed === 0 ? 'None' : $allowed.' group'.($allowed === 1 ? '' : 's') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-scroll-region>
    </section>

    @if ($canOperate)
        <h2 class="vd-settings__heading">Housekeeping</h2>

        <section class="vd-panel vd-settings__ops">
            <div>
                <p class="vd-settings__title">Clear the caches</p>
                <p class="vd-settings__blurb mb-0">
                    Empties the application, view and route caches. Safe to run at any
                    time; nothing is deleted but cached copies.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.settings.cache-clear') }}">
                @csrf
                <button type="submit" class="btn btn-outline-primary">Clear caches</button>
            </form>
        </section>
    @endif
@endsection
