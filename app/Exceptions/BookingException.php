<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A reservation could not be made: the dates are no longer free,
 * the room is unavailable, or a booking rule was not satisfied.
 *
 * Controllers show these messages to the guest, so every message
 * should be clear and understandable.
 */
class BookingException extends RuntimeException
{
    public static function unavailable(
        int $available,
        int $requested
    ): self {
        return new self(
            $available === 0
                ? 'Those dates have just been taken for this room type. Please choose other dates.'
                : "Only {$available} "
                    . str('room')->plural($available)
                    . ' of this type '
                    . ($available === 1 ? 'is' : 'are')
                    . " left for those dates, and you asked for {$requested}."
        );
    }

    public static function notBookable(): self
    {
        return new self(
            'This room type is not accepting bookings at the moment.'
        );
    }

    /**
     * A resort that has set no price for a day or night tour does not
     * run them. Said plainly, because a guest who picked one on a page
     * that was open while the owner withdrew it deserves to know why.
     */
    public static function typeNotOffered(string $type): self
    {
        return new self(
            "This room is not offered as a {$type}. Choose another option, "
            .'or contact the resort directly.'
        );
    }

    public static function tooManyGuests(
        int $capacity,
        int $rooms
    ): self {
        $total = $capacity * $rooms;

        return new self(
            "That room type takes {$capacity} "
                . str('guest')->plural($capacity)
                . " per room, so {$rooms} "
                . str('room')->plural($rooms)
                . " fits up to {$total}. Book another room or choose a larger type."
        );
    }

    /**
     * Legacy exception retained for existing booking-limit logic.
     */
    public static function tooManyPending(int $limit): self
    {
        return new self(
            "You already have {$limit} reservations waiting for a reply. "
                . 'Please wait for those before making another.'
        );
    }

    /**
     * Prevent a tourist from creating another booking while
     * a pending or confirmed booking already exists.
     */
    public static function activeBookingExists(): self
    {
        return new self(
            'You already have an active booking at another resort '
                . 'or accommodation. Please wait until your current '
                . 'booking is completed, cancelled, or rejected '
                . 'before making another booking.'
        );
    }

    public static function pastDate(): self
    {
        return new self(
            'Choose a check-in date from today onwards.'
        );
    }

    public static function badRange(): self
    {
        return new self(
            'The check-out date must be after the check-in date.'
        );
    }

    public static function tooLong(int $maxNights): self
    {
        return new self(
            "Online bookings can cover at most {$maxNights} nights. "
                . 'Contact the resort directly for a longer stay.'
        );
    }

    public static function tooFarAhead(): self
    {
        return new self(
            'That date is too far ahead to book online.'
        );
    }

    /**
     * A way of paying this resort does not accept for this stay.
     *
     * The form only offers the methods the resort's terms allow, so a
     * guest reaching this has sent something the page did not show
     * them: a form left open while the owner changed their policy, or
     * a request made by hand. Either way the answer is the same, and
     * the deposit stands.
     */
    public static function paymentMethodNotAllowed(string $method): self
    {
        return new self(
            "This resort does not accept {$method} for this booking. "
            .'Please reload the page and choose from the options shown.'
        );
    }
}
