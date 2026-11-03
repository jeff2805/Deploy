<?php

namespace App\Settings;

/**
 * Every setting the office can change, declared in one place.
 *
 * THIS FILE IS THE MODULE. The controller, the form, the validator,
 * the config override and the authorisation all read their answers
 * from here, so a new setting is one entry rather than an edit in five
 * files — and no form can ever accept a key the registry has not
 * declared, which is what keeps a settings screen from becoming a
 * write-anything hole into config.
 *
 * Each field carries:
 *
 *   type     how to render it and how to cast it
 *   label    what the person sees
 *   config   the config path it overrides, or null if it is new
 *   rules    Laravel validation, applied server side
 *   help     the sentence under the input
 *   options  for select fields
 *
 * A field with a `config` path needs no default of its own: the
 * default IS the config value, which is why config/visitdasol.php
 * stays the single source of truth for what the site does out of the
 * box. Delete the row and the file's value returns.
 */
class SettingsRegistry
{
    /**
     * The groups, in the order the hub lists them.
     *
     * `roles` is who may open and save the group. Only the three roles
     * this application actually has appear here — see the note in
     * App\Enums\UserRole about the posts that were retired.
     *
     * @return array<string, array{label: string, icon: string, blurb: string, roles: list<string>}>
     */
    public static function groups(): array
    {
        return [
            'general' => [
                'label' => 'General',
                'icon' => 'sliders',
                'blurb' => 'What the site calls itself, and how it reaches you.',
                'roles' => ['super_admin'],
            ],
            'municipal' => [
                'label' => 'Municipal information',
                'icon' => 'building',
                'blurb' => 'The office behind the site: address, hours, socials.',
                'roles' => ['super_admin'],
            ],
            'security' => [
                'label' => 'Security',
                'icon' => 'shield-lock',
                'blurb' => 'Passwords, sign-in attempts, one-time codes, sessions.',
                'roles' => ['super_admin'],
            ],
            'notifications' => [
                'label' => 'Notifications',
                'icon' => 'bell',
                'blurb' => 'Which events send mail, and to whom.',
                'roles' => ['super_admin'],
            ],
            'email' => [
                'label' => 'Email delivery',
                'icon' => 'envelope-at',
                'blurb' => 'The SMTP account the site sends from.',
                'roles' => ['super_admin'],
            ],
            'resorts' => [
                'label' => 'Resorts',
                'icon' => 'buildings',
                'blurb' => 'Registration, review and what a listing must carry.',
                'roles' => ['super_admin'],
            ],
            'bookings' => [
                'label' => 'Bookings',
                'icon' => 'calendar-check',
                'blurb' => 'How far ahead, how long, and when a stay may be cancelled.',
                'roles' => ['super_admin'],
            ],
            /*
            | THE PAYMENTS GROUP WAS HERE and was removed on request.
            |
            | Nothing about how VisitDasol takes money changed with it.
            | The deposit percentage, the payment deadline and the
            | on-site option are still read from config/visitdasol.php
            | by Booking, ResortPaymentPolicy and BookingService exactly
            | as they were before this settings module existed — what
            | is gone is only the screen that let the office edit the
            | municipal fallback.
            |
            | A resort's own payment policy was always the one that
            | decided, and a booking has always kept the terms it was
            | made under. Neither depended on this group.
            */
            'map' => [
                'label' => 'Map and GPS',
                'icon' => 'map',
                'blurb' => 'Where the map opens, which layers it offers, what it draws.',
                'roles' => ['super_admin'],
            ],
            'appearance' => [
                'label' => 'Appearance',
                'icon' => 'palette',
                'blurb' => 'Colours and the footer. The layout itself is not themeable.',
                'roles' => ['super_admin'],
            ],
            'maintenance' => [
                'label' => 'Maintenance',
                'icon' => 'cone-striped',
                'blurb' => 'Close the public site to visitors, and clear caches.',
                'roles' => ['super_admin'],
            ],
            'privacy' => [
                'label' => 'Privacy and terms',
                'icon' => 'file-earmark-text',
                'blurb' => 'The published policy, and how long records are kept.',
                'roles' => ['super_admin'],
            ],
        ];
    }

    /**
     * Every field, grouped.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function fields(): array
    {
        return [
            'general' => [
                'general.name' => [
                    'type' => 'text',
                    'label' => 'System name',
                    'config' => 'visitdasol.name',
                    'rules' => ['required', 'string', 'max:60'],
                    'help' => 'Shown in the header, the browser tab and every email.',
                ],
                'general.tagline' => [
                    'type' => 'text',
                    'label' => 'Website title line',
                    'config' => 'visitdasol.tagline',
                    'rules' => ['nullable', 'string', 'max:120'],
                    'help' => 'The line under the name on the public home page.',
                ],
                'general.description' => [
                    'type' => 'textarea',
                    'label' => 'System description',
                    'config' => null,
                    'rules' => ['nullable', 'string', 'max:500'],
                    'help' => 'Used as the page description search engines show.',
                ],
                'general.contact_email' => [
                    'type' => 'email',
                    'label' => 'Contact email',
                    'config' => 'visitdasol.contact.email',
                    'rules' => ['nullable', 'email', 'max:120'],
                    'help' => 'Where the public is told to write.',
                ],
                'general.contact_phone' => [
                    'type' => 'text',
                    'label' => 'Contact number',
                    'config' => 'visitdasol.contact.phone',
                    'rules' => ['nullable', 'string', 'max:40'],
                    'help' => null,
                ],
                'general.timezone' => [
                    'type' => 'select',
                    'label' => 'Timezone',
                    'config' => null,
                    'rules' => ['required', 'timezone'],
                    'options' => [
                        'Asia/Manila' => 'Asia/Manila (PHT)',
                        'UTC' => 'UTC',
                    ],
                    'help' => 'Dates on screen are written in this zone.',
                ],
                'general.currency' => [
                    'type' => 'select',
                    'label' => 'Currency',
                    'config' => null,
                    'rules' => ['required', 'in:PHP'],
                    'options' => ['PHP' => 'Philippine peso (PHP)'],
                    'help' => 'Peso only. Amounts are stored in centavos and the '
                        .'formatting is built around it.',
                ],
                'general.date_format' => [
                    'type' => 'select',
                    'label' => 'Date format',
                    'config' => null,
                    'rules' => ['required', 'in:j M Y,d/m/Y,m/d/Y,Y-m-d'],
                    'options' => [
                        'j M Y' => '2 Nov 2026',
                        'd/m/Y' => '02/11/2026',
                        'm/d/Y' => '11/02/2026',
                        'Y-m-d' => '2026-11-02',
                    ],
                    'help' => null,
                ],
                'general.time_format' => [
                    'type' => 'select',
                    'label' => 'Time format',
                    'config' => null,
                    'rules' => ['required', 'in:g:i A,H:i'],
                    'options' => ['g:i A' => '2:30 PM', 'H:i' => '14:30'],
                    'help' => null,
                ],
            ],

            'municipal' => [
                'municipal.municipality' => [
                    'type' => 'text',
                    'label' => 'Municipality',
                    'config' => 'visitdasol.municipality',
                    'rules' => ['required', 'string', 'max:80'],
                    'help' => null,
                ],
                'municipal.province' => [
                    'type' => 'text',
                    'label' => 'Province',
                    'config' => 'visitdasol.province',
                    'rules' => ['required', 'string', 'max:80'],
                    'help' => null,
                ],
                'municipal.postal_code' => [
                    'type' => 'text',
                    'label' => 'Postal code',
                    'config' => 'visitdasol.postal_code',
                    'rules' => ['nullable', 'string', 'max:10'],
                    'help' => null,
                ],
                'municipal.office_name' => [
                    'type' => 'text',
                    'label' => 'Tourism office name',
                    'config' => 'visitdasol.contact.office',
                    'rules' => ['nullable', 'string', 'max:120'],
                    'help' => null,
                ],
                'municipal.address' => [
                    'type' => 'textarea',
                    'label' => 'Office address',
                    'config' => 'visitdasol.contact.address',
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => null,
                ],
                'municipal.office_hours' => [
                    'type' => 'text',
                    'label' => 'Office hours',
                    'config' => null,
                    'rules' => ['nullable', 'string', 'max:120'],
                    'help' => 'For example: Monday to Friday, 8:00 AM to 5:00 PM.',
                ],
                'municipal.facebook' => [
                    'type' => 'url',
                    'label' => 'Facebook page',
                    'config' => 'visitdasol.contact.facebook_url',
                    'rules' => ['nullable', 'url', 'max:200'],
                    'help' => null,
                ],
                'municipal.instagram' => [
                    'type' => 'url',
                    'label' => 'Instagram',
                    'config' => null,
                    'rules' => ['nullable', 'url', 'max:200'],
                    'help' => null,
                ],
                'municipal.youtube' => [
                    'type' => 'url',
                    'label' => 'YouTube',
                    'config' => null,
                    'rules' => ['nullable', 'url', 'max:200'],
                    'help' => null,
                ],
            ],

            'security' => [
                'security.password_min' => [
                    'type' => 'number',
                    'label' => 'Minimum password length',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:8', 'max:64'],
                    'help' => 'Eight is the floor. Raising it does not affect '
                        .'passwords already set.',
                ],
                'security.password_mixed_case' => [
                    'type' => 'bool',
                    'label' => 'Require upper and lower case',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'security.password_numbers' => [
                    'type' => 'bool',
                    'label' => 'Require a number',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'security.password_symbols' => [
                    'type' => 'bool',
                    'label' => 'Require a symbol',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'security.login_max_attempts' => [
                    'type' => 'number',
                    'label' => 'Sign-in attempts before lockout',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:3', 'max:20'],
                    'help' => null,
                ],
                'security.lockout_minutes' => [
                    'type' => 'number',
                    'label' => 'Lockout length in minutes',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                    'help' => null,
                ],
                'security.otp_length' => [
                    'type' => 'number',
                    'label' => 'One-time code length',
                    'config' => 'visitdasol.otp.length',
                    'rules' => ['required', 'integer', 'min:4', 'max:8'],
                    'help' => null,
                ],
                'security.otp_minutes' => [
                    'type' => 'number',
                    'label' => 'One-time code lifetime in minutes',
                    // ttl_minutes, not minutes. Checked against the file.
                    'config' => 'visitdasol.otp.ttl_minutes',
                    'rules' => ['required', 'integer', 'min:1', 'max:60'],
                    'help' => null,
                ],
                'security.otp_max_attempts' => [
                    'type' => 'number',
                    'label' => 'Tries allowed per one-time code',
                    'config' => 'visitdasol.otp.max_attempts',
                    'rules' => ['required', 'integer', 'min:1', 'max:10'],
                    'help' => null,
                ],
                'security.require_email_verification' => [
                    'type' => 'bool',
                    'label' => 'Require email verification',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Turning this off does not verify anybody already waiting.',
                ],
                'security.session_timeout' => [
                    'type' => 'number',
                    'label' => 'Session timeout in minutes',
                    'config' => 'session.lifetime',
                    'rules' => ['required', 'integer', 'min:15', 'max:10080'],
                    'help' => 'How long a signed-in person stays signed in while idle.',
                ],
            ],

            'notifications' => [
                'notifications.booking_created' => [
                    'type' => 'bool',
                    'label' => 'New booking',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Email the resort owner when a stay is booked.',
                ],
                'notifications.booking_cancelled' => [
                    'type' => 'bool',
                    'label' => 'Cancelled booking',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'notifications.owner_registered' => [
                    'type' => 'bool',
                    'label' => 'Owner application',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Email the office when somebody applies to list a resort.',
                ],
                'notifications.resort_submitted' => [
                    'type' => 'bool',
                    'label' => 'Resort submitted for review',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'notifications.announcement_published' => [
                    'type' => 'bool',
                    'label' => 'Announcement published',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'notifications.message_received' => [
                    'type' => 'bool',
                    'label' => 'New message',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Email when a guest or owner writes and the other side is away.',
                ],
                'notifications.in_app' => [
                    'type' => 'bool',
                    'label' => 'Show in-app notices',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'The counters and badges inside the dashboard.',
                ],
            ],

            'email' => [
                'email.from_name' => [
                    'type' => 'text',
                    'label' => 'Sender name',
                    'config' => 'mail.from.name',
                    'rules' => ['required', 'string', 'max:80'],
                    'help' => null,
                ],
                'email.from_address' => [
                    'type' => 'email',
                    'label' => 'Sender email',
                    'config' => 'mail.from.address',
                    'rules' => ['required', 'email', 'max:120'],
                    'help' => null,
                ],
                'email.host' => [
                    'type' => 'text',
                    'label' => 'SMTP host',
                    'config' => 'mail.mailers.smtp.host',
                    'rules' => ['nullable', 'string', 'max:150'],
                    'help' => null,
                ],
                'email.port' => [
                    'type' => 'number',
                    'label' => 'SMTP port',
                    'config' => 'mail.mailers.smtp.port',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:65535'],
                    'help' => null,
                ],
                'email.username' => [
                    'type' => 'text',
                    'label' => 'SMTP username',
                    'config' => 'mail.mailers.smtp.username',
                    'rules' => ['nullable', 'string', 'max:150'],
                    'help' => null,
                ],
                'email.password' => [
                    'type' => 'secret',
                    'label' => 'SMTP password',
                    'config' => 'mail.mailers.smtp.password',
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => 'Stored encrypted. It is never sent back to this page — '
                        .'leave it blank to keep the one already saved.',
                ],
                'email.encryption' => [
                    'type' => 'select',
                    'label' => 'Encryption',
                    /*
                     * "scheme", not "encryption". Laravel 11 renamed it,
                     * and config/mail.php here has no encryption key at
                     * all — writing to the old name would have set a
                     * value nothing reads.
                     */
                    'config' => 'mail.mailers.smtp.scheme',
                    'rules' => ['nullable', 'in:smtp,smtps'],
                    'options' => ['smtp' => 'STARTTLS (port 587)', 'smtps' => 'TLS (port 465)'],
                    'help' => 'Most providers, Gmail included, use STARTTLS on 587.',
                ],
            ],

            'resorts' => [
                'resorts.registration_open' => [
                    'type' => 'bool',
                    'label' => 'Accept new resort registrations',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Closing this hides the application form. Applications '
                        .'already waiting are untouched.',
                ],
                'resorts.auto_approve' => [
                    'type' => 'bool',
                    'label' => 'Approve resorts automatically',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Off by default, and it should stay off: approval is how '
                        .'the office checks a listing is real.',
                ],
                'resorts.require_permit' => [
                    'type' => 'bool',
                    'label' => 'Require a business permit document',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'resorts.min_images' => [
                    'type' => 'number',
                    'label' => 'Minimum photographs per resort',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:0', 'max:20'],
                    'help' => null,
                ],
                'resorts.show_pending_publicly' => [
                    'type' => 'bool',
                    'label' => 'Show resorts awaiting review to the public',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
            ],

            'bookings' => [
                /*
                 * No config.booking.min_nights exists, so this one is
                 * genuinely new and carries its own default below. It
                 * is declared because the office asked for it; nothing
                 * in BookingService reads it yet, and the help text
                 * says so rather than implying otherwise.
                 */
                'bookings.min_nights' => [
                    'type' => 'number',
                    'label' => 'Minimum nights',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:1', 'max:30'],
                    'help' => 'Recorded as policy. The booking form does not enforce '
                        .'it yet.',
                ],
                'bookings.max_nights' => [
                    'type' => 'number',
                    'label' => 'Maximum nights',
                    'config' => 'visitdasol.booking.max_nights',
                    'rules' => ['required', 'integer', 'min:1', 'max:365'],
                    'help' => null,
                ],
                'bookings.max_advance_days' => [
                    'type' => 'number',
                    'label' => 'How far ahead a stay may be booked, in days',
                    'config' => 'visitdasol.booking.max_advance_days',
                    'rules' => ['required', 'integer', 'min:1', 'max:730'],
                    'help' => null,
                ],
                /*
                 * The real key is tourist_cancel_cutoff_days, and it is
                 * counted in DAYS. Declaring an "hours" field over it
                 * would have written 24 into a field meaning 24 days.
                 */
                'bookings.cancel_cutoff_days' => [
                    'type' => 'number',
                    'label' => 'Cancellation cut-off, in days before check-in',
                    'config' => 'visitdasol.booking.tourist_cancel_cutoff_days',
                    'rules' => ['required', 'integer', 'min:0', 'max:30'],
                    'help' => 'Zero lets a guest cancel up to the check-in date.',
                ],
                'bookings.max_pending_per_tourist' => [
                    'type' => 'number',
                    'label' => 'Unpaid bookings one guest may hold',
                    'config' => 'visitdasol.booking.max_pending_per_tourist',
                    'rules' => ['required', 'integer', 'min:1', 'max:20'],
                    'help' => null,
                ],
                'bookings.allow_day_tour' => [
                    'type' => 'bool',
                    'label' => 'Allow day tours',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Rooms still decide their own day-tour price.',
                ],
                'bookings.allow_overnight' => [
                    'type' => 'bool',
                    'label' => 'Allow overnight stays',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
            ],

            // The payments fields were here. See the note in groups().

            'map' => [
                'map.zoom' => [
                    'type' => 'number',
                    'label' => 'Opening zoom',
                    'config' => 'visitdasol.map.zoom',
                    'rules' => ['required', 'integer', 'min:1', 'max:20'],
                    'help' => 'Higher is closer. The map opens here.',
                ],
                'map.min_zoom' => [
                    'type' => 'number',
                    'label' => 'Furthest a visitor may zoom out',
                    'config' => 'visitdasol.map.min_zoom',
                    'rules' => ['required', 'integer', 'min:1', 'max:20'],
                    'help' => null,
                ],
                'map.max_zoom' => [
                    'type' => 'number',
                    'label' => 'Closest a visitor may zoom in',
                    'config' => 'visitdasol.map.max_zoom',
                    'rules' => ['required', 'integer', 'min:1', 'max:20'],
                    'help' => null,
                ],
                /*
                 * number, not text, and with a decimal step. Cast as
                 * text these would reach the map as the strings "15.99"
                 * and "119.88", and Leaflet is handed coordinates, not
                 * labels.
                 */
                'map.center_lat' => [
                    'type' => 'number',
                    'step' => 'any',
                    'label' => 'Centre latitude',
                    'config' => 'visitdasol.map.center.lat',
                    'rules' => ['required', 'numeric', 'between:-90,90'],
                    'help' => 'Dasol sits near 15.99. Changing this moves where the '
                        .'map opens, not where any resort is.',
                ],
                'map.center_lng' => [
                    'type' => 'number',
                    'step' => 'any',
                    'label' => 'Centre longitude',
                    'config' => 'visitdasol.map.center.lng',
                    'rules' => ['required', 'numeric', 'between:-180,180'],
                    'help' => null,
                ],
                'map.allow_satellite' => [
                    'type' => 'bool',
                    'label' => 'Offer the satellite layer',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'map.allow_hybrid' => [
                    'type' => 'bool',
                    'label' => 'Offer the hybrid layer',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Imagery with place and road names drawn over it.',
                ],
                'map.allow_geolocation' => [
                    'type' => 'bool',
                    'label' => 'Offer "My location"',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'The browser still asks the visitor before any position '
                        .'is read, and no position is ever stored.',
                ],
                'map.show_resorts' => [
                    'type' => 'bool',
                    'label' => 'Show resort pins',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'map.show_destinations' => [
                    'type' => 'bool',
                    'label' => 'Show destination pins',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'map.show_barangays' => [
                    'type' => 'bool',
                    'label' => 'Show barangay names',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'map.allow_routing' => [
                    'type' => 'bool',
                    'label' => 'Offer road distance and directions',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Driving distances come from the public OSRM service.',
                ],
            ],

            'appearance' => [
                'appearance.primary_color' => [
                    'type' => 'color',
                    'label' => 'Primary colour',
                    'config' => null,
                    'rules' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    'help' => 'The ocean blue used for headers and primary buttons.',
                ],
                'appearance.secondary_color' => [
                    'type' => 'color',
                    'label' => 'Secondary colour',
                    'config' => null,
                    'rules' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    'help' => null,
                ],
                'appearance.accent_color' => [
                    'type' => 'color',
                    'label' => 'Accent colour',
                    'config' => null,
                    'rules' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    'help' => null,
                ],
                'appearance.footer_text' => [
                    'type' => 'textarea',
                    'label' => 'Footer text',
                    'config' => null,
                    'rules' => ['nullable', 'string', 'max:300'],
                    'help' => null,
                ],
                'appearance.show_announcements' => [
                    'type' => 'bool',
                    'label' => 'Show announcements on the home page',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
                'appearance.show_destinations' => [
                    'type' => 'bool',
                    'label' => 'Show destinations on the home page',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => null,
                ],
            ],

            'maintenance' => [
                'maintenance.enabled' => [
                    'type' => 'bool',
                    'label' => 'Close the public site',
                    'config' => null,
                    'rules' => ['boolean'],
                    'help' => 'Visitors see the message below. Signed-in office staff '
                        .'keep full access, and the sign-in page stays open, so this '
                        .'cannot lock you out.',
                ],
                'maintenance.message' => [
                    'type' => 'textarea',
                    'label' => 'Message for visitors',
                    'config' => null,
                    'rules' => ['nullable', 'string', 'max:300'],
                    'help' => null,
                ],
            ],

            'privacy' => [
                'privacy.policy_url' => [
                    'type' => 'url',
                    'label' => 'Privacy policy link',
                    'config' => null,
                    'rules' => ['nullable', 'url', 'max:200'],
                    'help' => null,
                ],
                'privacy.terms_url' => [
                    'type' => 'url',
                    'label' => 'Terms and conditions link',
                    'config' => null,
                    'rules' => ['nullable', 'url', 'max:200'],
                    'help' => null,
                ],
                'privacy.retention_months' => [
                    'type' => 'number',
                    'label' => 'Keep activity records for, in months',
                    'config' => null,
                    'rules' => ['required', 'integer', 'min:1', 'max:120'],
                    'help' => 'A statement of policy. Nothing is deleted automatically '
                        .'by saving this.',
                ],
                'privacy.contact_email' => [
                    'type' => 'email',
                    'label' => 'Where to send a data request',
                    'config' => null,
                    'rules' => ['nullable', 'email', 'max:120'],
                    'help' => null,
                ],
            ],
        ];
    }

    /**
     * Fallbacks for fields with no config path of their own.
     *
     * Kept apart from the field definitions so that the answer to
     * "what does this site do before anybody touches settings" is one
     * readable list rather than a value buried in each entry.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'general.description' => 'Plan a stay in Dasol, Pangasinan: resorts, beaches and how to reach them.',
            'general.timezone' => 'Asia/Manila',
            'general.currency' => 'PHP',
            'general.date_format' => 'j M Y',
            'general.time_format' => 'g:i A',

            'municipal.office_hours' => 'Monday to Friday, 8:00 AM to 5:00 PM',
            'municipal.instagram' => null,
            'municipal.youtube' => null,

            'security.password_min' => 8,
            'security.password_mixed_case' => true,
            'security.password_numbers' => true,
            'security.password_symbols' => false,
            'security.login_max_attempts' => 5,
            'security.lockout_minutes' => 15,
            'security.require_email_verification' => true,

            'notifications.booking_created' => true,
            'notifications.booking_cancelled' => true,
            'notifications.owner_registered' => true,
            'notifications.resort_submitted' => true,
            'notifications.announcement_published' => false,
            'notifications.message_received' => true,
            'notifications.in_app' => true,

            'resorts.registration_open' => true,
            'resorts.auto_approve' => false,
            'resorts.require_permit' => true,
            'resorts.min_images' => 1,
            'resorts.show_pending_publicly' => false,

            'bookings.min_nights' => 1,
            'bookings.allow_day_tour' => true,
            'bookings.allow_overnight' => true,

            'map.allow_satellite' => true,
            'map.allow_hybrid' => true,
            'map.allow_geolocation' => true,
            'map.show_resorts' => true,
            'map.show_destinations' => true,
            'map.show_barangays' => true,
            'map.allow_routing' => true,

            'appearance.primary_color' => '#0D3B47',
            'appearance.secondary_color' => '#0F7C7A',
            'appearance.accent_color' => '#E8D9B8',
            'appearance.footer_text' => null,
            'appearance.show_announcements' => true,
            'appearance.show_destinations' => true,

            'maintenance.enabled' => false,
            'maintenance.message' => 'VisitDasol is closed for maintenance. Please come back shortly.',

            'privacy.policy_url' => null,
            'privacy.terms_url' => null,
            'privacy.retention_months' => 24,
            'privacy.contact_email' => null,
        ];
    }

    /** Every declared key, flat. @return list<string> */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::fields() as $fields) {
            foreach (array_keys($fields) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** One field definition, or null if the key was never declared. */
    public static function field(string $key): ?array
    {
        foreach (self::fields() as $fields) {
            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function fieldsIn(string $group): array
    {
        return self::fields()[$group] ?? [];
    }

    public static function hasGroup(string $group): bool
    {
        return isset(self::groups()[$group]);
    }
}
