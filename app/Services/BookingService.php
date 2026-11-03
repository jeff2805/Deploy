<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentPreference;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingRequested;
use App\Services\Payments\PaymentPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingReferenceGenerator $references,
        private readonly ActivityLogger $logger,
        private readonly Notifier $notifier,
        private readonly BookingPricer $pricer,
        private readonly PaymentPolicyService $policy,
        private readonly BookingExtraService $extras,
    ) {}

    /**
     * Keep empty values as null instead of converting them to zero.
     */
    private function countOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '')
            ? null
            : (int) $value;
    }

    /**
     * The guest's chosen hour, else the resort's standard one, else the
     * hour most resorts keep. Always an HH:MM, never null.
     */
    private function timeOrStandard(
        ?string $chosen,
        ?string $standard,
        string $fallback
    ): string {
        foreach ([$chosen, $standard] as $candidate) {
            if (
                is_string($candidate)
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d/', $candidate)
            ) {
                return substr($candidate, 0, 5);
            }
        }

        return $fallback;
    }

    /**
     * @param array{
     *     check_in_date: string,
     *     check_out_date: string,
     *     check_in_time?: ?string,
     *     check_out_time?: ?string,
     *     guests: int|string,
     *     rooms_booked: int|string,
     *     contact_name: string,
     *     contact_phone: string,
     *     adults?: int|string,
     *     children?: int|string,
     *     pwd_guests?: int|string,
     *     contact_email?: ?string,
     *     special_requests?: ?string,
     *     payment_preference?: ?string,
     *     guests_male?: int|string|null,
     *     guests_female?: int|string|null,
     *     origin?: ?string
     * } $data
     *
     * @throws BookingException
     */
    public function create(
        User $tourist,
        int $roomId,
        array $data
    ): Booking {
        $checkIn = CarbonImmutable::parse(
            $data['check_in_date']
        )->startOfDay();

        $type = BookingType::tryFrom((string) ($data['booking_type'] ?? ''))
            ?? BookingType::Overnight;

        /*
         * A TOUR IS ONE DATE TO THE GUEST, AND [D, D+1) TO THE ENGINE.
         *
         * AvailabilityService counts the nights in the half-open range
         * [check_in, check_out) and SKIPS any booking whose two dates
         * are equal. A tour stored on one date would therefore hold no
         * inventory at all, and the same cottage could be sold to an
         * unlimited number of day groups.
         *
         * Storing the day after blocks the date exactly as one night
         * does, with no change to the sweep. nights = 0, set below, is
         * what tells the pricing and the screens nobody slept there.
         */
        $checkOut = $type->isTour()
            ? $checkIn->addDay()
            : CarbonImmutable::parse($data['check_out_date'])->startOfDay();

        $roomsBooked = (int) $data['rooms_booked'];

        $children = (int) ($data['children'] ?? 0);
        $pwdGuests = (int) ($data['pwd_guests'] ?? 0);

        /*
         * Adults are what is left of the party once the children are
         * taken out, so a caller that names only a party size still gets
         * the party it asked for rather than a booking for one.
         */
        $adults = isset($data['adults'])
            ? (int) $data['adults']
            : max(1, (int) ($data['guests'] ?? 1) - $children);

        $this->guardDates($checkIn, $checkOut);

        $this->guardGuestCounts(
            $adults,
            $children,
            $pwdGuests,
            $roomsBooked
        );

        // PWD guests are a subset of adults + children, not additional guests.
        $guests = $adults + $children;

        return DB::transaction(function () use (
            $tourist,
            $roomId,
            $checkIn,
            $checkOut,
            $guests,
            $adults,
            $children,
            $pwdGuests,
            $roomsBooked,
            $data,
            $type
        ): Booking {
            /*
             * Lock the tourist row so simultaneous booking requests
             * for the same account are serialized.
             */
            $lockedTourist = User::query()
                ->whereKey($tourist->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTourist === null) {
                throw BookingException::notBookable();
            }

            /*
             * Allow only one unfinished booking per tourist,
             * regardless of resort, room, or booking dates.
             */
            $this->guardSingleActiveBooking($lockedTourist);

            // Lock the selected room to protect room inventory.
            $room = Room::query()
                ->whereKey($roomId)
                ->lockForUpdate()
                ->first();

            if ($room === null) {
                throw BookingException::notBookable();
            }

            $room->load('resort.owner');

            if (! $room->isBookable()) {
                throw BookingException::notBookable();
            }

            /*
             * A resort that has set no day-tour price does not run day
             * tours. Checked here as well as on the form, because a page
             * left open while the owner withdrew the option must not be
             * able to buy something that is no longer sold — and a room
             * with no price for it would otherwise book for nothing.
             */
            if (! $room->offers($type)) {
                throw BookingException::typeNotOffered($type->label());
            }

            if ($guests > $room->capacity * $roomsBooked) {
                throw BookingException::tooManyGuests(
                    $room->capacity,
                    $roomsBooked
                );
            }

            $available = $this->availability->availableUnits(
                $room,
                $checkIn,
                $checkOut
            );

            if ($available < $roomsBooked) {
                throw BookingException::unavailable(
                    $available,
                    $roomsBooked
                );
            }

            /*
             * Zero for a tour, whatever the stored dates say. The day
             * after is how the room is held, not a night anybody slept.
             */
            $nights = $type->isTour()
                ? 0
                : (int) $checkIn->diffInDays($checkOut);

            /*
             * The bill, discounts included, worked out here and never
             * taken from the form. The same sums run in the browser so
             * the guest sees them before sending; this is the copy that
             * is charged.
             */
            $price = $this->pricer->quote(
                $room,
                $nights,
                $roomsBooked,
                $adults,
                $children,
                $pwdGuests,
                $type
            );

            /*
             * A booking always carries an arrival and departure hour, even
             * when the caller named none. The form fills these in from the
             * resort before it gets here, but the seeder and the office's
             * own tools call this method directly, and a stay with no hour
             * on it is one BookingStatusManager cannot close.
             */
            [$defaultIn, $defaultOut] = $type->defaultHours();

            /*
             * A tour keeps its own hours. The resort's standard 2 p.m.
             * check-in belongs to somebody sleeping over; a day group
             * arriving then would have missed most of the day they paid
             * for.
             */
            $checkInTime = $this->timeOrStandard(
                $data['check_in_time'] ?? null,
                $type->isTour() ? null : $room->resort->check_in_time,
                $defaultIn
            );

            $checkOutTime = $this->timeOrStandard(
                $data['check_out_time'] ?? null,
                $type->isTour() ? null : $room->resort->check_out_time,
                $defaultOut
            );

            /*
             * The payment terms, worked out from the resort's policy as it
             * stands right now and then frozen onto the booking.
             *
             * Frozen on purpose, and frozen for pending requests too: the
             * guest was shown these terms on the form before they sent it,
             * and an owner who raises their deposit while a request is
             * waiting must not have it apply to the request already in
             * front of them. A policy change takes effect on the next
             * booking, never on one already made.
             */
            $terms = $this->policy->terms(
                $room->resort->paymentTerms(),
                $price->total,
                $checkIn
            );

            /*
             * THE WAY OF PAYING HAS TO BE ONE THE RESORT ACCEPTS.
             *
             * Checked HERE because this is the one place that holds
             * the real terms: the real total, the real arrival date,
             * and the resort's policy as it stands at this instant.
             * The form offers the same list and the request validates
             * against it, but both of those are built before the guest
             * presses send — and a form left open while the owner
             * switched off cash on arrival would otherwise turn a
             * required deposit into nothing to pay.
             *
             * Refused rather than silently corrected. Quietly moving a
             * guest from "pay at the resort" to "pay a deposit now"
             * changes what they agreed to.
             */
            $preference = $data['payment_preference'] ?? null;

            if ($preference !== null) {
                $choice = $preference instanceof PaymentPreference
                    ? $preference
                    : PaymentPreference::tryFrom((string) $preference);

                $account = $room->resort->manualAccount()
                    ->where('active', true)
                    ->first();

                if ($choice === null || ! $choice->isAvailable($account, $terms)) {
                    throw BookingException::paymentMethodNotAllowed(
                        $choice?->label() ?? 'that payment method'
                    );
                }
            }

            $booking = new Booking([
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $data['contact_email'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,

                // Preference only; no payment is processed here.
                'payment_preference' =>
                    $data['payment_preference'] ?? null,

                // Tourism reporting fields.
                'guests_male' => $this->countOrNull(
                    $data['guests_male'] ?? null
                ),

                'guests_female' => $this->countOrNull(
                    $data['guests_female'] ?? null
                ),

                'origin' => $data['origin'] ?? null,
            ]);

            $booking->forceFill([
                'booking_reference' =>
                    $this->references->generate($checkIn),

                'user_id' => $lockedTourist->id,

                'resort_id' => $room->resort_id,
                'room_id' => $room->id,

                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,

                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,

                'booking_type' => $type,

                'nights' => $nights,
                'guests' => $guests,
                'adults' => $adults,
                'children' => $children,
                'pwd_guests' => $pwdGuests,
                'rooms_booked' => $roomsBooked,

                // What the party actually gets, so a guest arriving to
                // fewer beds than the page promised has it in writing.
                'beds' => (int) $room->bed_count * $roomsBooked,

                /*
                 * The rate this booking was actually sold at, which for
                 * a tour is its own price and not the nightly one. The
                 * bill is printed from this figure, so storing the wrong
                 * one would leave a breakdown that does not add up to
                 * the total beneath it.
                 */
                'unit_price' => $room->priceFor($type) ?? $room->price_per_night,

                'subtotal_amount' => $price->subtotal,
                'child_discount_amount' => $price->childDiscount,
                'pwd_discount_amount' => $price->pwdDiscount,
                'discount_total' => $price->discountTotal,
                'total_amount' => $price->total,

                'child_discount_percent' => $price->childPercent,
                'pwd_discount_percent' => $price->pwdPercent,

                'status' => BookingStatus::Pending,
            ] + $terms->toSnapshot())->save();

            $this->logger->log(
                'booking.created',
                $booking,
                "Requested {$booking->booking_reference} at "
                    . "{$room->resort->name}",
                [
                    'nights' => $nights,
                    'rooms' => $roomsBooked,
                    'adults' => $adults,
                    'children' => $children,
                    'pwd_guests' => $pwdGuests,
                    'subtotal_amount' => $price->subtotal,
                    'child_discount_amount' => $price->childDiscount,
                    'pwd_discount_amount' => $price->pwdDiscount,
                    'discount_total' => $price->discountTotal,
                    'total_amount' => $price->total,
                    'amount_due_now' => $terms->dueNow,
                    'payment_requirement' => $terms->requirement->value,
                    'payment_policy_version' => $terms->policyVersion,
                    'check_in_time' => $checkInTime,
                    'check_out_time' => $checkOutTime,
                ],
                $lockedTourist,
            );

            /*
             * What the guest asked for besides the room — a kayak, a
             * banana boat ride.
             *
             * REQUESTS, AND NOTHING ABOVE IS RECALCULATED. The prices
             * are read from the resort's own list and snapshotted by
             * the service; none of them reaches subtotal_amount,
             * total_amount or the payment terms, because extras are
             * settled at the resort. The money the portal says is owed
             * stays the money for the room.
             */
            $this->extras->request($booking, (array) ($data['extras'] ?? []));

            // Notify the resort owner after saving.
            $booking->setRelation('resort', $room->resort)
                ->setRelation('room', $room);

            $this->notifier->send(
                $room->resort->owner,
                new BookingRequested($booking)
            );

            return $booking;
        });
    }

    /**
     * Validate guest counts and ensure PWD count is a subset
     * of the total number of guests.
     */
    private function guardGuestCounts(
        int $adults,
        int $children,
        int $pwdGuests,
        int $roomsBooked
    ): void {
        if (
            $adults < 1
            || $children < 0
            || $pwdGuests < 0
            || $roomsBooked < 1
        ) {
            throw ValidationException::withMessages([
                'guests' => 'Please provide valid guest and room counts.',
            ]);
        }

        if ($pwdGuests > ($adults + $children)) {
            throw ValidationException::withMessages([
                'pwd_guests' => 'PWD guests cannot exceed the total guest count.',
            ]);
        }
    }

    /**
     * The unfinished stay stopping this tourist booking again, if any.
     *
     * Public so the booking form can warn BEFORE a guest fills it in.
     * The refusal below asks the same question of the same scope, so the
     * warning and the refusal can never disagree.
     *
     * The resort and room are loaded because every caller names them:
     * "you already have a booking" is not much use without saying where.
     */
    public function activeBookingFor(User $tourist): ?Booking
    {
        return Booking::query()
            ->where('user_id', $tourist->id)
            ->unfinished()
            ->with(['resort:id,name,slug,barangay', 'room:id,name'])
            ->latest('id')
            ->first();
    }

    /**
     * A tourist can have only one unfinished booking.
     *
     * Pending and confirmed bookings block new bookings.
     * Rejected, cancelled, and completed bookings do not block.
     *
     * Still checked here even though the form warns first: the warning
     * is drawn when the page loads, and a guest with two tabs open
     * could still get this far.
     */
    private function guardSingleActiveBooking(User $tourist): void
    {
        $hasActiveBooking = Booking::query()
            ->where('user_id', $tourist->id)
            ->unfinished()
            ->exists();

        if ($hasActiveBooking) {
            throw BookingException::activeBookingExists();
        }
    }

    /**
     * Calculate total using centavos to avoid floating-point drift.
     */
    public function total(
        float|string $pricePerNight,
        int $nights,
        int $rooms
    ): string {
        $centavosPerNight = (int) round(
            (float) $pricePerNight * 100
        );

        $centavos = $centavosPerNight * $nights * $rooms;

        return number_format(
            $centavos / 100,
            2,
            '.',
            ''
        );
    }

    private function guardDates(
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut
    ): void {
        $limits = config('visitdasol.booking');

        if ($checkIn->lt(CarbonImmutable::today())) {
            throw BookingException::pastDate();
        }

        if ($checkOut->lte($checkIn)) {
            throw BookingException::badRange();
        }

        if (
            $checkIn->diffInDays($checkOut)
            > $limits['max_nights']
        ) {
            throw BookingException::tooLong(
                (int) $limits['max_nights']
            );
        }

        if (
            $checkIn->gt(
                CarbonImmutable::today()->addDays(
                    (int) $limits['max_advance_days']
                )
            )
        ) {
            throw BookingException::tooFarAhead();
        }
    }
}
