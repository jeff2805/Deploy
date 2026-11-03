<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Models\ResortManualPaymentAccount;
use App\Services\Payments\PaymentTerms;

enum PaymentPreference: string
{
    use EnumHelpers;

    case Online = 'online';
    case OnArrival = 'on_arrival';
    case ManualGcash = 'manual_gcash';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'QRPh',
            self::OnArrival => 'Pay at the resort',
            self::ManualGcash => 'Manual GCash',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Online =>
                'Pay through PayMongo QRPh once the resort has confirmed.',
            self::OnArrival =>
                'Settle in person, in cash, on the day you arrive.',
            self::ManualGcash =>
                'Send payment to the resort owner through GCash after confirmation.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Online => 'phone',
            self::OnArrival => 'cash-coin',
            self::ManualGcash => 'qr-code',
        };
    }

    /**
     * Whether this resort may be paid this way for this stay.
     *
     * TWO QUESTIONS, NOT ONE. Whether the METHOD exists at all — is
     * PayMongo configured, has the resort set up a GCash account — and
     * whether the resort's payment terms ALLOW it for this booking.
     * Manual GCash was once offered by resorts asking for no deposit
     * at all, sending the guest to collect a reference number and
     * upload a receipt for a payment nobody wanted yet.
     *
     * THIS IS THE ONE GATE. The booking form draws what it returns,
     * StoreBookingRequest accepts what it returns, and BookingService
     * refuses anything else against the real terms. A method withdrawn
     * here is withdrawn everywhere, which is why "Pay at the resort"
     * needed no change to a view, a route or a rule.
     *
     * $terms is optional, and with none the answers are about the site
     * rather than one stay: a report, a seeder, a screen listing what
     * exists. Every caller deciding for a real booking passes them.
     */
    public function isAvailable(
        ?ResortManualPaymentAccount $manualAccount = null,
        ?PaymentTerms $terms = null,
    ): bool {
        /*
         * Paying in advance when nothing is due in advance. Both the
         * prepaid methods are hidden, for the same reason: there is no
         * amount for them to collect.
         */
        $prepaidWanted = $terms === null || $terms->needsAdvancePayment();

        return match ($this) {
            self::Online => PaymentProvider::PayMongo->isConfigured()
                && $prepaidWanted,

            /*
             * WITHDRAWN. "Pay at the resort" is no longer offered for
             * new bookings, so it is never available — not even where
             * the resort's policy still allows cash on arrival.
             *
             * THE CASE STAYS, and deleting it would be the mistake.
             * bookings.payment_preference is cast to this enum, so a
             * reservation made while the option existed would throw
             * the moment anybody opened it: the guest's own page, the
             * owner's list, the office reports. History has to remain
             * readable, and label() and icon() below still describe it
             * for exactly that.
             *
             * The resort owner's allow_on_site switch is a DIFFERENT
             * thing and is untouched. It still decides the payment
             * terms, the sentence a guest reads about settling at the
             * resort, and the last-minute rule.
             */
            self::OnArrival => false,

            self::ManualGcash => (bool) (
                $manualAccount && $manualAccount->active
            ) && $prepaidWanted,
        };
    }

    /**
     * @return list<self>
     */
    public static function available(
        ?ResortManualPaymentAccount $manualAccount = null,
        ?PaymentTerms $terms = null,
    ): array {
        return array_values(array_filter(
            self::cases(),
            fn (self $preference) =>
                $preference->isAvailable($manualAccount, $terms)
        ));
    }
}