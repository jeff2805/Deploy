{{-- Municipal Tourism Office navigation (see Phase 0, section 27). --}}
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.dashboard" icon="speedometer2">Dashboard</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Approvals</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.owner-applications.index" match="admin.owner-applications.*" icon="person-check" :count="$pendingOwnerCount ?? null">Owner applications</x-sidebar-link>
    <x-sidebar-link route="admin.resort-applications.index" match="admin.resort-applications.*" icon="building-check" :count="$pendingResortCount ?? null">Resort applications</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">People and listings</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.users.index" match="admin.users.*" icon="people">Users</x-sidebar-link>
    <x-sidebar-link route="admin.owners.index" match="admin.owners.*" icon="person-badge">Resort owners</x-sidebar-link>
    <x-sidebar-link route="admin.resorts.index" match="admin.resorts.*" icon="buildings">Resorts</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Tourism content</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.destinations.index" match="admin.destinations.*" icon="signpost-2">Destinations</x-sidebar-link>
    <x-sidebar-link route="admin.destination-categories.index" match="admin.destination-categories.*" icon="tags">Destination categories</x-sidebar-link>
    <x-sidebar-link route="admin.announcements.index" match="admin.announcements.*" icon="megaphone">Announcements</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Monitoring</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.bookings.index" match="admin.bookings.*" icon="journal-check">Bookings</x-sidebar-link>
    <x-sidebar-link route="admin.map" icon="map">Map</x-sidebar-link>
    <x-sidebar-link route="admin.reports.index" match="admin.reports.*" icon="graph-up">Reports</x-sidebar-link>
    <x-sidebar-link route="admin.activity-logs.index" icon="clock-history">Activity log</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Account</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="profile.edit" icon="person-circle">Profile</x-sidebar-link>
    <x-sidebar-link route="admin.settings.index" match="admin.settings.*" icon="gear">Settings</x-sidebar-link>
    @if (Route::has('logout'))
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="vd-sidebar__link">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Sign out</span>
                </button>
            </form>
        </li>
    @endif
</ul>
