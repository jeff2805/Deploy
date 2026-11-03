<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentPreference;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Choosing how to pay, on the booking form itself.
 *
 * The choice is intent, not money: a request the resort can still decline
 * must never be charged for, because nothing here could send it back. These
 * lock that boundary from both ends — the choice is carried through and
 * honoured, and no payment is possible until the resort has confirmed.
 */
class PaymentChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        // A key present means the gateway counts as switched on. Nothing is
        // ever really called: no test here reaches the network.
        config([
            'services.paymongo.secret' => 'sk_test_pretend',
            'services.paymongo.methods' => ['qrph'],
        ]);

        $this->tourist = User::factory()->create();
        $this->room = Room::factory()
            ->for(Resort::factory()->active())
            ->create(['capacity' => 4, 'total_rooms' => 3, 'price_per_night' => 600]);
    }

    /** @param  array<string, mixed>  $extra */
    private function request(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), array_merge([
            'room' => $this->room->id,
            'check_in_date' => '2030-07-20',
            'check_out_date' => '2030-07-22',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            // Required, for the office's monthly return to the Department of
            // Tourism. They must add up to the guests above.
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ], $extra));
    }

    private function formUrl(): string
    {
        return route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-20',
            'check_out' => '2030-07-22',
        ]);
    }

    /* What the form offers ------------------------------------------------ */

    /**
     * "Pay at the resort" is no longer offered.
     *
     * IT USED TO BE, and this test used to require it. The option was
     * withdrawn for new bookings; the enum case remains only so that
     * reservations made while it existed can still be read.
     */
    public function test_the_booking_form_no_longer_offers_paying_at_the_resort(): void
    {
        $this->actingAs($this->tourist)->get($this->formUrl())
            ->assertOk()
            ->assertDontSee('Pay at the resort')
            ->assertDontSee('value="on_arrival"', false)
            // The summary still looks like a bill, so this has to stay said.
            ->assertSee('Nothing is charged now.');
    }

    /**
     * With no key there is no way to produce a QRPh link, so the form must
     * not offer one. Every resort then collects at the gate as before.
     */
    public function test_no_choice_is_offered_when_no_gateway_is_configured(): void
    {
        config(['services.paymongo.secret' => null]);

        $this->actingAs($this->tourist)->get($this->formUrl())
            ->assertOk()
            ->assertDontSee('How would you like to pay?')
            ->assertSee('Nothing is charged now.');
    }

    /* Carrying the choice through ----------------------------------------- */

    public function test_choosing_qrph_is_kept_on_the_reservation(): void
    {
        $this->request(['payment_preference' => 'online'])->assertSessionHasNoErrors();

        $this->assertSame(PaymentPreference::Online, Booking::latest('id')->sole()->payment_preference);
    }

    /**
     * Sending the withdrawn choice by hand does not book.
     *
     * This is the server half of the removal: taking the radio off the
     * page stops nobody who can type a form field.
     */
    public function test_choosing_to_pay_at_the_resort_is_refused(): void
    {
        $this->request(['payment_preference' => 'on_arrival']);

        $this->assertSame(0, Booking::query()->count());
    }

    /** Requests made before this field existed, and anyone posting without it. */
    public function test_a_request_that_says_nothing_still_books(): void
    {
        $this->request()->assertSessionHasNoErrors();

        $this->assertNull(Booking::latest('id')->sole()->payment_preference);
    }

    public function test_a_made_up_choice_is_refused(): void
    {
        $this->request(['payment_preference' => 'bank_transfer'])
            ->assertSessionHasErrors('payment_preference');

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * Hand-posting "online" with no gateway configured would record an
     * intention the system could never act on, so it is refused rather than
     * quietly stored.
     */
    public function test_qrph_cannot_be_chosen_when_it_is_not_switched_on(): void
    {
        config(['services.paymongo.secret' => null]);

        $this->request(['payment_preference' => 'online'])
            ->assertSessionHasErrors('payment_preference');

        $this->assertDatabaseCount('bookings', 0);
    }

    /* What each side is told afterwards ------------------------------------ */

    public function test_a_waiting_guest_is_told_when_the_qrph_button_appears(): void
    {
        $this->request(['payment_preference' => 'online']);
        $booking = Booking::latest('id')->sole();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Paying by QRPh')
            ->assertSee('as soon as')
            // Still pending, so there is nothing to pay yet.
            ->assertDontSee('Pay online');
    }

    public function test_the_qrph_button_appears_once_the_resort_confirms(): void
    {
        $this->request(['payment_preference' => 'online']);
        $booking = Booking::latest('id')->sole();
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Pay online')
            ->assertSee('QRPh');
    }

    public function test_the_resort_is_told_what_the_guest_chose(): void
    {
        $this->request(['payment_preference' => 'online']);
        $online = Booking::latest('id')->sole();

        $this->actingAs($this->room->resort->owner)->get(route('owner.bookings.show', $online))
            ->assertOk()
            ->assertSee('Guest chose to pay by')
            ->assertSee('QRPh')
            ->assertSee('intends to pay by QRPh once you confirm');
    }

    /**
     * A reservation made while "Pay at the resort" was still offered.
     *
     * MADE DIRECTLY, not through the form, because the form will not
     * accept that choice any more. The point of the test is unchanged
     * and is now more important than it was: the owner's screen has to
     * go on reading bookings taken under the old option.
     */
    public function test_a_resort_expecting_cash_is_told_to_collect_as_usual(): void
    {
        $this->request();
        $arrival = Booking::latest('id')->sole();

        $arrival->forceFill([
            'payment_preference' => PaymentPreference::OnArrival,
        ])->save();

        $this->actingAs($this->room->resort->owner)->get(route('owner.bookings.show', $arrival))
            ->assertOk()
            ->assertSee('Pay at the resort')
            ->assertSee('Collect it at your resort as usual.');
    }

    /**
     * The guest is not sitting on the site when the resort confirms, so the
     * one mail they do get has to say that paying is now open.
     */
    public function test_the_confirmation_email_tells_a_qrph_guest_they_can_pay_now(): void
    {
        $this->request(['payment_preference' => 'online']);
        $booking = Booking::latest('id')->sole();

        $mail = (new \App\Notifications\BookingDecided($booking->fresh(), BookingStatus::Confirmed))
            ->toMail($this->tourist)
            ->render();

        $this->assertStringContainsString('You chose to pay by QRPh', $mail);
    }

    /** Again on an older booking, for the reason given above. */
    public function test_that_line_is_left_out_when_the_guest_is_paying_at_the_resort(): void
    {
        $this->request();
        $booking = Booking::latest('id')->sole();

        $booking->forceFill([
            'payment_preference' => PaymentPreference::OnArrival,
        ])->save();

        $mail = (new \App\Notifications\BookingDecided($booking->fresh(), BookingStatus::Confirmed))
            ->toMail($this->tourist)
            ->render();

        $this->assertStringNotContainsString('You chose to pay by QRPh', $mail);
    }

    /* What PayMongo is actually asked for ---------------------------------- */

    public function test_paymongo_is_asked_for_qrph_only(): void
    {
        Http::fake(['api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_pretend_123',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_pretend_123'],
            ],
        ])]);

        $this->request(['payment_preference' => 'online']);
        $booking = Booking::latest('id')->sole();
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

        $this->actingAs($this->tourist)
            ->post(route('tourist.payments.start', $booking), ['provider' => 'paymongo']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request['data']['attributes']['payment_method_types'] === ['qrph'];
        });
    }
}
