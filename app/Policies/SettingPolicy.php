<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingsRegistry;

/**
 * Who may read and change the system settings.
 *
 * ONLY THE OFFICE. Every group declared in the registry today is
 * municipal configuration — the map the public sees, the payment
 * defaults, the SMTP account — and none of it belongs to a resort
 * owner or a guest. They manage their own account through the profile
 * screen that already exists, which this module does not touch.
 *
 * THE ROLES THE BRIEF ASKED FOR AND THIS APPLICATION HAS ARE NOT THE
 * SAME LIST. There is no MTO Staff and no fee collector: those posts
 * were retired with the environmental fee module, and App\Enums\UserRole
 * now holds three cases. Rather than invent permissions for accounts
 * that cannot exist, this policy grants on the roles that do, and the
 * group definitions in SettingsRegistry name them. Adding a staff role
 * later means adding it to the enum and to the group's `roles` list;
 * nothing here would have to be rewritten.
 */
class SettingPolicy
{
    /** Whether the settings area appears at all. */
    public function viewAny(User $user): bool
    {
        return $this->groupsFor($user) !== [];
    }

    /**
     * Whether this person may open one group.
     *
     * Called as authorize('viewGroup', [Setting::class, $group]).
     */
    public function viewGroup(User $user, string $group): bool
    {
        $definition = SettingsRegistry::groups()[$group] ?? null;

        if ($definition === null) {
            return false;
        }

        return in_array($this->roleValue($user), $definition['roles'], true);
    }

    /**
     * Whether this person may save one group.
     *
     * The same answer as viewGroup today, kept as its own method so a
     * read-only role can be added without touching the controller.
     */
    public function updateGroup(User $user, string $group): bool
    {
        return $this->viewGroup($user, $group);
    }

    /** Clearing caches and closing the site are the same authority. */
    public function operate(User $user): bool
    {
        return $this->roleValue($user) === UserRole::SuperAdmin->value;
    }

    /**
     * The groups this person may open, in registry order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function groupsFor(User $user): array
    {
        $role = $this->roleValue($user);
        $out = [];

        foreach (SettingsRegistry::groups() as $key => $definition) {
            if (in_array($role, $definition['roles'], true)) {
                $out[$key] = $definition;
            }
        }

        return $out;
    }

    private function roleValue(User $user): string
    {
        return $user->role instanceof UserRole
            ? $user->role->value
            : (string) $user->role;
    }
}
