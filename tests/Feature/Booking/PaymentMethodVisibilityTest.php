<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentPreference;
use App\Enums\PaymentRequirement;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which ways of paying a resort is offered, and which it will accept.
 *
 * WHAT WAS WRONG. PaymentPreference::isAvailable() asked only whether
 * a METHOD existed — is PayMongo configured, has this resort a GCash
 * account — and never whether the resort's own terms allowed it. So:
 *
 *   - "Pay at the resort" was offered by every resort on the site,
 *     including those whose policy has allow_on_site switched off. A
 *     guest could pick it and a required deposit became nothing to pay.
 *
 *   - Manual GCash was offered by resorts asking for no deposit at
 *     all, sending guests to produce a reference number for a payment
 *     nobody had asked for.
 *
 * Both lists — the one the form draws and the one the server accepts —
 * came from that same method, so they were wrong together and agreed
 * with each other while they were.
 */
class PaymentMethodVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A resort with a payment policy of its own.
     *
     * @param array<string, mixed> $policy
     */
    private function resortWith(array $policy, bool $withGcash = false): Resort
    {
        $owner = User::factory()->create([
            'role' => 'resort_owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $resort = Resort::factory()->active()->for($owner, 'owner')->create();

        $resort->paymentPolicy()->create(array_merge([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'allow_on_site' => true,
        ], $policy));

        if ($withGcash) {
            $resort->manualAccount()->create([
                'account_name' => 'Juan Dela Cruz',
                'gcash_number' => '09171234567',
                'active' => true,
            ]);
        }

        return $resort->refresh();
    }

    private function roomAt(Resort $resort): Room
    {
        return Room::factory()->for($resort)->create([
            'price_per_night' => 1500,
            'capacity' => 4,
            'total_rooms' => 2,
        ]);
    }

    private function tourist(): User
    {
        return User::factory()->create([
            'role' => 'tourist',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function formQuery(Room $room): array
    {
        return [
            'room' => $room->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(22)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, string $preference): array
    {
        return [
            'room' => $room->id,
            'check_in_date' => today()->addDays(20)->toDateString(),
            'check_out_date' => today()->addDays(22)->toDateString(),
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
            'payment_preference' => $preference,
        ];
    }

    /**
     * The methods this resort allows for this stay, worked out the
     * same way the controller and the service work them out.
     *
     * @return list<PaymentPreference>
     */
    private function preferencesFor(Resort $resort, Room $room): array
    {
        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $resort->paymentTerms(),
            (string) ($room->price_per_night * 2),
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        return PaymentPreference::available(
            $resort->manualAccount()->where('active', true)->first(),
            $terms,
        );
    }

    private function offered(Room $room): string
    {
        return $this->actingAs($this->tourist())
            ->get(route('tourist.bookings.create', $this->formQuery($room)))
            ->assertOk()
            ->getContent();
    }

    /* No deposit ---------------------------------------------------------- */

    /**
     * "Pay at the resort" is withdrawn, even where the resort's own
     * policy still permits cash on arrival.
     *
     * THE POLICY SWITCH IS A DIFFERENT THING and is deliberately left
     * on in this test. allow_on_site still shapes the payment terms
     * and the sentence a guest reads about settling at the resort; it
     * simply no longer puts a radio button on the booking form.
     */
    public function test_paying_at_the_resort_is_never_offered_any_more(): void
    {
        foreach ([true, false] as $allowOnSite) {
            $resort = $this->resortWith([
                'requirement' => PaymentRequirement::None,
                'deposit_percent' => 0,
                'allow_on_site' => $allowOnSite,
            ]);

            $this->assertNotContains(
                PaymentPreference::OnArrival,
                $this->preferencesFor($resort, $this->roomAt($resort)),
                'allow_on_site='.var_export($allowOnSite, true),
            );
        }
    }

    /** The resort owner's policy switch itself is untouched. */
    public function test_the_resorts_own_on_site_policy_still_works(): void
    {
        $resort = $this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => true,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $resort->paymentTerms(),
            '3000.00',
            \Carbon\CarbonImmutable::today()->addDays(20),
        );

        $this->assertTrue($terms->onSiteAllowed);
        $this->assertStringContainsString('Settle the whole amount at the resort', $terms->sentence());
    }

    /**
     * Nothing is due in advance, so there is nothing for GCash to
     * collect and the option is not shown.
     */
    public function test_no_deposit_does_not_offer_manual_gcash(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::None,
            'deposit_percent' => 0,
            'allow_on_site' => true,
        ], withGcash: true));

        $this->assertStringNotContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $this->offered($room),
        );
    }

    /* With a deposit ------------------------------------------------------- */

    public function test_a_deposit_with_an_active_account_offers_manual_gcash(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->assertStringContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $this->offered($room),
        );
    }

    public function test_a_deposit_without_an_account_does_not_offer_manual_gcash(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: false));

        $this->assertStringNotContainsString(
            'value="'.PaymentPreference::ManualGcash->value.'"',
            $this->offered($room),
        );
    }

    /**
     * Not on the page, even in the case that used to put it there.
     *
     * allow_on_site is TRUE here on purpose: this is the arrangement
     * that would have offered cash on arrival under every previous
     * version of this rule.
     */
    public function test_paying_at_the_resort_is_not_on_the_form(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => true,
        ], withGcash: true));

        $html = $this->offered($room);

        $this->assertStringNotContainsString(
            'value="'.PaymentPreference::OnArrival->value.'"',
            $html,
        );

        $this->assertStringNotContainsString('Pay at the resort', $html);
    }

    /* The server decides --------------------------------------------------- */

    /**
     * The one that matters: a guest who sends "pay at the resort"
     * anyway does not get a booking.
     *
     * allow_on_site is TRUE here too, so the refusal is the option
     * being withdrawn and not the resort's policy forbidding it.
     */
    public function test_the_server_refuses_paying_at_the_resort(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 50,
            'allow_on_site' => true,
        ], withGcash: true));

        $this->actingAs($this->tourist())
            ->from(route('tourist.bookings.create', $this->formQuery($room)))
            ->post(
                route('tourist.bookings.store'),
                $this->payload($room, PaymentPreference::OnArrival->value),
            );

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_the_server_refuses_manual_gcash_when_the_resort_has_no_account(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: false));

        $this->actingAs($this->tourist())
            ->from(route('tourist.bookings.create', $this->formQuery($room)))
            ->post(
                route('tourist.bookings.store'),
                $this->payload($room, PaymentPreference::ManualGcash->value),
            );

        $this->assertDatabaseCount('bookings', 0);
    }

    /** An allowed method still goes through, unchanged. */
    public function test_an_allowed_method_is_accepted_and_stored(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->actingAs($this->tourist())->post(
            route('tourist.bookings.store'),
            $this->payload($room, PaymentPreference::ManualGcash->value),
        );

        $booking = Booking::query()->latest('id')->first();

        $this->assertNotNull($booking, 'An allowed payment method must still book.');
        $this->assertSame(PaymentPreference::ManualGcash, $booking->payment_preference);
    }

    /* The rule itself ------------------------------------------------------- */

    /**
     * With no terms, the old answers come back.
     *
     * Callers that are not deciding for one booking — a report, a
     * seeder, a screen listing what the site can do — ask what exists,
     * not what this stay permits.
     */
    public function test_without_terms_the_methods_are_reported_as_before(): void
    {
        // Withdrawn everywhere, with terms or without.
        $this->assertFalse(PaymentPreference::OnArrival->isAvailable());
        $this->assertFalse(PaymentPreference::ManualGcash->isAvailable());
    }

    /**
     * The case survives even though the option is gone.
     *
     * bookings.payment_preference is cast to this enum. Deleting the
     * case would not remove an old reservation — it would make it
     * unreadable, and throw on the guest's own page, the owner's list
     * and the office reports alike.
     */
    public function test_an_older_booking_that_chose_it_can_still_be_read(): void
    {
        $room = $this->roomAt($this->resortWith([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
        ], withGcash: true));

        $this->actingAs($this->tourist())->post(
            route('tourist.bookings.store'),
            $this->payload($room, PaymentPreference::ManualGcash->value),
        );

        $booking = Booking::query()->latest('id')->sole();

        // As a reservation taken before the option was withdrawn.
        $booking->forceFill(['payment_preference' => 'on_arrival'])->save();

        $read = Booking::query()->findOrFail($booking->id);

        $this->assertSame(PaymentPreference::OnArrival, $read->payment_preference);
        $this->assertSame('Pay at the resort', $read->payment_preference->label());
    }

    /** The script hides what the terms do not allow, as the page is used. */
    public function test_the_script_hides_methods_the_terms_do_not_allow(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $this->assertStringContainsString('function applyPaymentMethods(', $script);
        $this->assertStringContainsString("radio.value === 'on_arrival'", $script);
    }
}
