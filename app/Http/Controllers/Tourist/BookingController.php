<?php

namespace App\Http\Controllers\Tourist;

use App\Enums\PaymentPreference;
use App\Enums\PaymentProvider;
use App\Enums\PaymentRequirement;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tourist\CancelBookingRequest;
use App\Http\Requests\Tourist\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\BookingPricer;
use App\Services\BookingService;
use App\Services\BookingStatusManager;
use App\Services\Payments\ManualPaymentService;
use App\Services\Payments\PaymentPolicyService;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $bookings,
        private readonly BookingStatusManager $status,
        private readonly PaymentService $payments,
        private readonly BookingPricer $pricer,
        private readonly PaymentPolicyService $policies,
    ) {}

    /**
     * Display the tourist's bookings.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Booking::class);

        return view('tourist.bookings.index', [
            'bookings' => $request->user()
                ->bookings()
                ->with([
                    'resort:id,name,slug,barangay',
                    'room:id,name',
                ])
                ->latest()
                ->paginate(config('visitdasol.per_page')),
        ]);
    }

    /**
     * Display the booking request form.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Booking::class);

        $room = Room::query()
            ->with('resort')
            ->find($request->query('room'));

        if ($room === null || ! $room->isBookable()) {
            return redirect()
                ->route('resorts.index')
                ->with(
                    'error',
                    'That room type is not available for booking.'
                );
        }

        /*
         * One reservation per guest at a time. Checked here, before the
         * form is drawn, rather than only when it is sent: a guest who
         * has filled in names, dates, a guest breakdown and a payment
         * choice should not learn at the last press that none of it
         * could ever have been accepted.
         *
         * BookingService refuses it as well. This is the warning; that
         * is the rule.
         */
        $activeBooking = $this->bookings->activeBookingFor($request->user());

        if ($activeBooking !== null) {
            return view('tourist.bookings.blocked', [
                'activeBooking' => $activeBooking,
                'resort' => $room->resort,
                'room' => $room,
            ]);
        }

        $checkIn = $this->date($request->query('check_in'));
        $checkOut = $this->date($request->query('check_out'));

        /*
         * A tour is one date, so a link that carries one is not a
         * half-filled search — it is the whole of what a day group was
         * asked for. The day after opens the form; the guest picks the
         * kind on it, and the server derives the hold either way.
         */
        if ($checkIn !== null && $checkOut === null) {
            $checkOut = $checkIn->addDay();
        }

        $guests = max(
            1,
            (int) $request->query('guests', 1)
        );

        $roomsBooked = max(
            1,
            (int) $request->query('rooms', 1)
        );

        // Preserve valid times passed from the homepage.
        $checkInTime = $this->time(
            $request->query('check_in_time')
        ) ?? '14:00';

        $checkOutTime = $this->time(
            $request->query('check_out_time')
        ) ?? '12:00';

        $nights = (
            $checkIn !== null
            && $checkOut !== null
            && $checkOut->gt($checkIn)
        )
            ? (int) $checkIn->diffInDays($checkOut)
            : 0;

        /*
         * Load the active Manual GCash account for the selected resort.
         */
        $manualGcashAccount = $room->resort
            ->manualAccount()
            ->where('active', true)
            ->first();

        /*
         * What this resort asks for up front, on this stay, if the
         * guest sends the request now. Recalculated on the server when
         * they actually do, so a page left open while the owner changed
         * their terms cannot lock in a stale quote.
         *
         * WORKED OUT BEFORE THE PAYMENT METHODS, because it decides
         * them: a resort that takes no cash on arrival must not be
         * offered "Pay at the resort", and one that asks for nothing in
         * advance must not be offered a way to pay in advance.
         */
        $terms = $this->policies->terms(
            $room->resort->paymentTerms(),
            $nights > 0
                ? $this->bookings->total($room->price_per_night, $nights, $roomsBooked)
                : '0.00',
            $checkIn ?? CarbonImmutable::today()
        );

        $preferences = PaymentPreference::available(
            $manualGcashAccount,
            $terms
        );

        return view('tourist.bookings.create', [
            'room' => $room,
            'resort' => $room->resort,

            'rooms' => $room->resort
                ->rooms()
                ->bookable()
                ->orderBy('name')
                ->get(),

            'checkIn' => $checkIn,
            'checkOut' => $checkOut,

            // Booking times for the Blade form.
            'checkInTime' => $checkInTime,
            'checkOutTime' => $checkOutTime,

            'guests' => $guests,
            'roomsBooked' => $roomsBooked,
            'nights' => $nights,

            'available' => $nights > 0
                ? $this->availability->availableUnits(
                    $room,
                    $checkIn,
                    $checkOut
                )
                : null,

            'total' => $nights > 0
                ? $this->bookings->total(
                    $room->price_per_night,
                    $nights,
                    $roomsBooked
                )
                : null,

            /*
             * The opening figures in the summary. The form recalculates
             * them in the browser as the guest changes the party, but it
             * must not be the browser that first draws them: the page has
             * to read correctly before any script has run.
             */
            'price' => $this->pricer->quote(
                $room,
                $nights,
                $roomsBooked,
                $guests,
                0,
                0
            ),

            /*
             * What this resort offers besides the room. Live listings
             * only — something the owner has switched off is not
             * offered, and the service refuses it again if an id for
             * one is posted anyway.
             */
            'extras' => $room->resort->extras()->active()->inOrder()->get(),

            'manualGcashAccount' => $manualGcashAccount,
            'preferences' => $preferences,

            // Worked out above, where the payment methods are chosen from it.
            'terms' => $terms,
        ]);
    }

    /**
     * Preview the selected resort's saved payment terms for the live form.
     * This endpoint is display-only; BookingService recalculates everything
     * again when the booking is submitted.
     */
    public function previewPaymentTerms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room' => ['required', 'integer', 'exists:rooms,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'total' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $room = Room::query()
            ->with('resort')
            ->findOrFail($validated['room']);

        abort_unless($room->isBookable(), 422, 'This room is not available for booking.');

        $terms = $this->policies->terms(
            $room->resort->paymentTerms(),
            (string) $validated['total'],
            CarbonImmutable::createFromFormat('!Y-m-d', $validated['check_in_date'])
        );

        return response()->json([
            'requirement' => $terms->requirement->value,
            'deposit_percent' => $terms->depositPercent,
            'total' => $terms->total,
            'due_now' => $terms->dueNow,
            'balance' => $terms->balance,
            'due_at' => $terms->dueAt?->format('M j, Y g:i A'),
            'sentence' => $terms->sentence(),
            'last_minute' => $terms->lastMinute,
            'on_site_allowed' => $terms->onSiteAllowed,
            'needs_advance_payment' => $terms->needsAdvancePayment(),
            'has_balance' => $terms->balance !== '0.00',

            /*
             * THE MONEY, ALREADY WRITTEN OUT, and the label that goes
             * above it.
             *
             * The browser never formats a peso and never decides what
             * to call the amount. If it did there would be two places
             * that know "₱1,500.00" and two that know when a deposit
             * stops being a deposit, and they would drift. Money::peso
             * writes these, the same helper the Blade uses.
             */
            'formatted' => [
                'total' => Money::peso($terms->total, true),
                'due_now' => Money::peso($terms->dueNow, true),
                'balance' => Money::peso($terms->balance, true),
                'due_label' => $terms->requirement === PaymentRequirement::Full
                    ? 'To pay before arrival'
                    : 'Deposit to pay ('.$terms->depositPercent.'%)',
            ],
        ]);
    }

    /**
     * Store the tourist's booking request.
     */
    public function store(
        StoreBookingRequest $request
    ): RedirectResponse {
        $this->authorize('create', Booking::class);

        $validated = $request->safe()->only([
            'check_in_date',
            'check_out_date',
            'check_in_time',
            'check_out_time',
            'booking_type',
            'guests',
            'adults',
            'children',
            'pwd_guests',
            'rooms_booked',
            'contact_name',
            'contact_phone',
            'contact_email',
            'special_requests',
            'payment_preference',
            'guests_male',
            'guests_female',
            'origin',
            'extras',
        ]);

        try {
            $booking = $this->bookings->create(
                $request->user(),
                (int) $request->validated('room'),
                $validated,
            );
        } catch (BookingException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('tourist.bookings.show', $booking)
            ->with(
                'success',
                'Request sent. The resort will confirm or decline it, '
                . 'and you can follow the status here.'
            );
    }

    /**
     * Display the booking confirmation and payment information.
     */
    public function show(Booking $booking): View
    {
        $this->authorize('view', $booking);

        $booking->load([
            'resort',
            'room.images',
            'cancelledBy',
            'payments',
            'review',
        ]);

        $manualPayments = app(ManualPaymentService::class);

        return view('tourist.bookings.show', [
            'booking' => $booking,
            'gateways' => PaymentProvider::available(),
            'manualPayable' => $manualPayments->isAvailable($booking),
            'outstanding' => $this->payments->outstanding($booking),

            // What the resort asked for before arrival, less what has
            // already been settled. This, not the whole bill, is what a
            // "Pay now" button may ever ask for.
            'dueNow' => $this->payments->dueNow($booking),
            'paid' => $this->payments->outstanding($booking) < (float) $booking->total_amount
                ? (float) $booking->total_amount - $this->payments->outstanding($booking)
                : 0.0,

            // The terms the booking was made under, read back from its own
            // snapshot rather than from the resort's current policy.
            'terms' => $this->policies->stored($booking),

            'payable' => $this->payments->isPayable($booking),
        ]);
    }

    /**
     * Cancel a tourist's booking.
     */
    public function cancel(
        CancelBookingRequest $request,
        Booking $booking
    ): RedirectResponse {
        $this->authorize('cancelAsTourist', $booking);

        try {
            $this->status->cancel(
                $booking,
                $request->user(),
                $request->reason()
            );
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('tourist.bookings.show', $booking)
            ->with(
                'success',
                'Your reservation has been cancelled and the rooms '
                . 'are free for other guests.'
            );
    }

    /**
     * Parse and validate a YYYY-MM-DD date.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (
            ! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

            if (
                $date === false
                || $date->format('Y-m-d') !== $value
            ) {
                return null;
            }

            return $date;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse a valid 24-hour HH:MM time.
     */
    private function time(mixed $value): ?string
    {
        if (
            ! is_string($value)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)
        ) {
            return null;
        }

        return $value;
    }
}