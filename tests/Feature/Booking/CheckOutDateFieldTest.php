<?php

namespace Tests\Feature\Booking;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The check-out date on the booking form.
 *
 * THE FIELD WAS ALREADY THERE. The column, the input, the validation
 * rule and the nights arithmetic all predate this test; what was
 * missing was one thing, and it is the thing these tests mostly
 * guard: the picker's own minimum never moved.
 *
 * The box was built with min="tomorrow" once, when the page was
 * rendered. A guest arriving in March could pick a check-out in
 * January and the browser would accept it; only the server said no,
 * after the whole form had been filled in and sent. The minimum now
 * follows the arrival date.
 */
class CheckOutDateFieldTest extends TestCase
{
    use RefreshDatabase;

    private function bookableRoom(): Room
    {
        $owner = User::factory()->create([
            'role' => 'resort_owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $resort = Resort::factory()->active()->for($owner, 'owner')->create();

        return Room::factory()->for($resort)->create([
            'price_per_night' => 1500,
            'capacity' => 4,
            'total_rooms' => 2,
        ]);
    }

    /**
     * A complete booking, with only the dates worth varying.
     *
     * The form asks for rather more than dates — who is coming, how
     * many of each, where from, how to reach them — and a payload
     * missing any of it fails on those instead of on the thing under
     * test.
     *
     * @param array<string, mixed> $dates
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $dates): array
    {
        return array_merge([
            'room' => $room->id,
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ], $dates);
    }

    /**
     * The query the booking form is reached with.
     *
     * The page only draws the form once it has a stay to show: with no
     * dates it answers "Choose your dates on the resort page first",
     * which is correct behaviour and would make every assertion below
     * pass or fail for the wrong reason.
     *
     * @return array<string, mixed>
     */
    private function formQuery(Room $room): array
    {
        return [
            'room' => $room->id,
            'check_in' => today()->addDays(5)->toDateString(),
            'check_out' => today()->addDays(7)->toDateString(),
        ];
    }

    private function tourist(): User
    {
        return User::factory()->create([
            'role' => 'tourist',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /* The form ----------------------------------------------------------- */

    public function test_the_form_offers_a_check_out_date_beside_the_check_in_date(): void
    {
        $room = $this->bookableRoom();

        $html = $this->actingAs($this->tourist())
            ->get(route('tourist.bookings.create', $this->formQuery($room)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="check_in_date"', $html);
        $this->assertStringContainsString('name="check_out_date"', $html);

        // Side by side: both halves of one row.
        $this->assertStringContainsString('data-booking-check-in', $html);
        $this->assertStringContainsString('data-booking-check-out', $html);
    }

    /**
     * Both dates come before both times.
     *
     * Asserting on the ORDER rather than mere presence, because "above
     * the time fields" is the part of the layout a later edit could
     * undo without anything else noticing.
     */
    public function test_the_dates_appear_above_the_times(): void
    {
        $room = $this->bookableRoom();

        $html = $this->actingAs($this->tourist())
            ->get(route('tourist.bookings.create', $this->formQuery($room)))
            ->assertOk()
            ->getContent();

        $checkInDate = strpos($html, 'name="check_in_date"');
        $checkOutDate = strpos($html, 'name="check_out_date"');
        $checkInTime = strpos($html, 'name="check_in_time"');
        $checkOutTime = strpos($html, 'name="check_out_time"');

        $this->assertNotFalse($checkInDate);
        $this->assertNotFalse($checkOutDate);
        $this->assertNotFalse($checkInTime);
        $this->assertNotFalse($checkOutTime);

        $this->assertLessThan($checkOutDate, $checkInDate, 'Check-in date should come before check-out date.');
        $this->assertLessThan($checkInTime, $checkOutDate, 'Both dates should come above the time fields.');
        $this->assertLessThan($checkOutTime, $checkInTime);
    }

    /** A tour has one date, so the check-out box folds away. */
    public function test_the_check_out_box_is_foldable_for_bookings_that_are_not_overnight(): void
    {
        $room = $this->bookableRoom();

        $html = $this->actingAs($this->tourist())
            ->get(route('tourist.bookings.create', $this->formQuery($room)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-booking-checkout-field', $html);
    }

    /* The picker's minimum ------------------------------------------------ */

    /** The new behaviour, in the script that performs it. */
    public function test_the_script_moves_the_minimum_with_the_check_in_date(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $this->assertStringContainsString('function dayAfter(', $script);
        $this->assertStringContainsString('checkOut.min = floor', $script);

        // A date below the new floor is corrected, not left to fail.
        $this->assertStringContainsString('checkOut.value = floor', $script);

        // And a tour's hidden box is not left carrying a stale floor.
        $this->assertStringContainsString("checkOut.removeAttribute('min')", $script);
    }

    /**
     * The floor is worked out from local date parts.
     *
     * toISOString() on a local midnight prints the previous evening in
     * UTC+8, which would put the "day after" back on the arrival day
     * and let a guest check out the morning they arrived. The guard is
     * that the helper never reaches for it.
     */
    public function test_the_day_after_helper_does_not_go_through_utc(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $start = strpos($script, 'function dayAfter(');
        $this->assertNotFalse($start);

        $body = substr($script, $start, 600);

        $this->assertStringNotContainsString('toISOString', $body,
            'dayAfter() must build its answer from local date parts: toISOString '
            .'shifts Manila back a day.');
    }

    /** The minimum must move before the nights are counted from it. */
    public function test_the_minimum_is_applied_before_the_nights_are_counted(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $floor = strpos($script, 'checkOut.min = floor');
        $nights = strpos($script, 'var nights = overnight');

        $this->assertNotFalse($floor);
        $this->assertNotFalse($nights);

        $this->assertLessThan($nights, $floor,
            'A corrected check-out date must be in place before the nights and the '
            .'price are worked out from it, or the total lags a refresh behind.');
    }

    /* The server still decides --------------------------------------------- */

    public function test_an_overnight_stay_ending_before_it_starts_is_refused(): void
    {
        $room = $this->bookableRoom();

        $this->actingAs($this->tourist())
            ->from(route('tourist.bookings.create', ['room' => $room->id]))
            ->post(route('tourist.bookings.store'), $this->payload($room, [
                'check_in_date' => today()->addDays(10)->toDateString(),
                'check_out_date' => today()->addDays(3)->toDateString(),
            ]))
            ->assertSessionHasErrors('check_out_date');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_the_chosen_check_out_date_sets_the_nights_and_the_price(): void
    {
        $room = $this->bookableRoom();

        $this->actingAs($this->tourist())
            ->post(route('tourist.bookings.store'), $this->payload($room, [
                'check_in_date' => today()->addDays(5)->toDateString(),
                'check_out_date' => today()->addDays(8)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $booking = \App\Models\Booking::query()->latest('id')->first();

        $this->assertNotNull($booking);
        $this->assertSame(3, (int) $booking->nights, 'Three nights were chosen.');
        $this->assertSame(
            today()->addDays(8)->toDateString(),
            $booking->check_out_date->toDateString(),
        );
    }
}
