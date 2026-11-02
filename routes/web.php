<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\DestinationCategoryController as AdminDestinationCategoryController;
use App\Http\Controllers\Admin\DestinationController as AdminDestinationController;
use App\Http\Controllers\Admin\DestinationImageController as AdminDestinationImageController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\MapController as AdminMapController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\OwnerApplicationController;
use App\Http\Controllers\Admin\ResortApplicationController;
use App\Http\Controllers\Admin\ResortController as AdminResortController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapOccupancyController;
use App\Http\Controllers\PlaceSearchController;
use App\Http\Controllers\RouteProfileController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ResortController;
use App\Http\Controllers\Tourist\BookingController as TouristBookingController;
use App\Http\Controllers\Tourist\PaymentController as TouristPaymentController;
use App\Http\Controllers\Tourist\ReviewController as TouristReviewController;
use App\Http\Controllers\Owner\ReviewController as OwnerReviewController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\OwnerController as AdminOwnerController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Settings\SettingsRegistry;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Owner\AmenityController as OwnerAmenityController;
use App\Http\Controllers\Owner\AvailabilityController as OwnerAvailabilityController;
use App\Http\Controllers\Owner\BookingController as OwnerBookingController;
use App\Http\Controllers\Owner\ManualGcashController;
use App\Http\Controllers\Owner\PaymentPolicyController;
use App\Http\Controllers\Owner\PaymentProofController;
use App\Http\Controllers\Owner\ResortController as OwnerResortController;
use App\Http\Controllers\Owner\StatisticsController as OwnerStatisticsController;
use App\Http\Controllers\Owner\BookingExtraController as OwnerBookingExtraController;
use App\Http\Controllers\Owner\ResortExtraController as OwnerExtraController;
use App\Http\Controllers\Owner\RoomController as OwnerRoomController;
use App\Http\Controllers\Owner\RoomImageController as OwnerRoomImageController;
use App\Http\Controllers\Owner\ResortImageController as OwnerResortImageController;
use App\Http\Controllers\Auth\OwnerRegistrationController;
use App\Http\Controllers\Auth\TouristRegistrationController;
use App\Http\Controllers\Tourist\ManualPaymentController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\SetupCheckController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Visit Dasol web routes
|--------------------------------------------------------------------------
|
| Routes are grouped by audience and filled in phase by phase:
|
|   PUBLIC          no middleware
|   AUTHENTICATION  guest / auth                       (Phase 3)
|   SHARED          auth, active                       (Phase 3)
|   TOURIST         /tourist  auth, active, role       (Phases 11, 13)
|   RESORT OWNER    /owner    auth, active, role       (Phases 6, 8, 12)
|   MTO             /admin    auth, active, role       (Phases 4, 7, 9, 14-17)
|
| Never change data with a GET route.
|
*/

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('terms');

Route::get('/resorts', [ResortController::class, 'index'])->name('resorts.index');
Route::get('/resorts/{resort:slug}', [ResortController::class, 'show'])->name('resorts.show');

/*
| The site calls these "destinations", and so do the route names, the
| model, the table and the morph map. The URL still says "tourist-spots",
| which is the one place the two differ.
|
| It is left that way on purpose. Links to /tourist-spots are already out
| in the world — shared, bookmarked, and indexed by search engines — and
| changing a public URL costs that for nothing a visitor can see. The
| redirect below keeps the older /destinations links working too, so
| every address anyone holds still arrives in the right place.
|
| If the office ever does want the URL to match, flip these two lines and
| reverse the redirect. Nothing else has to move.
*/
Route::get('/tourist-spots', [DestinationController::class, 'index'])->name('destinations.index');
Route::get('/tourist-spots/{destination:slug}', [DestinationController::class, 'show'])->name('destinations.show');

// Anyone holding an old link still arrives in the right place.
Route::permanentRedirect('/destinations', '/tourist-spots');
Route::permanentRedirect('/destinations/{slug}', '/tourist-spots/{slug}');

Route::get('/map', MapController::class)->name('map');

/*
| How full each resort is, for the dates the visitor picked on the map.
|
| Public, because the map is: deciding where to stay should not need an
| account. Throttled because it is the one map control that can be worked
| repeatedly, and every call sweeps the reservations table.
|
| It returns counts and a status word per resort, and nothing that could
| identify a guest or name a reservation. It is a snapshot for display:
| nothing is held, and BookingService re-checks availability under a row
| lock when a booking is actually submitted.
*/
Route::get('/map/occupancy', MapOccupancyController::class)
    ->middleware('throttle:60,1')
    ->name('map.occupancy');

/*
| Turning a typed place name into a point, for the map's "From" box.
|
| Public, because the map is. Throttled because it stands in front of
| OpenStreetMap's free search and their terms ask callers not to hammer it;
| PlaceFinder caches every answer, including the misses, so a repeated
| spelling never reaches them twice.
*/
Route::get('/places/search', PlaceSearchController::class)
    ->middleware('throttle:30,1')
    ->name('places.search');

/*
| Whether a drawn route climbs or drops. POST, because the shape of the road
| is sent up: a few dozen points, too many for a query string.
*/
Route::post('/routes/profile', RouteProfileController::class)
    ->middleware('throttle:30,1')
    ->name('routes.profile');

Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
Route::get('/announcements/{announcement:slug}', [AnnouncementController::class, 'show'])->name('announcements.show');

/*
| Payment gateways calling in. Not a page: the caller is PayMongo's or
| PayPal's server, so there is no session, no login, and no CSRF token to
| present (see bootstrap/app.php, where this path is excluded).
|
| Nothing in the body is believed. It is used only to find which payment is
| being talked about; the payment itself is then read back from the gateway.
*/
Route::post('/payments/webhook/{provider}', [TouristPaymentController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('payments.webhook');

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/register', [TouristRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [TouristRegistrationController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/register/owner', [OwnerRegistrationController::class, 'create'])->name('register.owner');
    Route::post('/register/owner', [OwnerRegistrationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/register/owner/submitted', [OwnerRegistrationController::class, 'submitted'])->name('register.owner.submitted');

    /*
    | Signing in with Google or Facebook.
    |
    | Both addresses answer 404 unless that provider's keys are set, and the
    | buttons are not on the page without them either, so nothing here is
    | reachable by accident. Tourist accounts only; see App\Services\SocialLogin.
    */
    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
        ->middleware('throttle:20,1')
        ->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('social.callback');

    // Password reset by email link
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

// Signing out must work even for an account that was just deactivated,
// so this route uses "auth" without "active".
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
| Email confirmation by one-time code. These routes are deliberately NOT
| behind "verified": they are where an unconfirmed account is sent, so
| guarding them would leave nowhere to go.
*/
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::post('/verify-email', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('verification.verify');
    Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.resend');
});

/*
|--------------------------------------------------------------------------
| SHARED (any signed-in, active account)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->middleware('verified')->name('dashboard');

    // Profile stays reachable without confirming the address. Someone who
    // mistyped their email must be able to correct it; saving a new address
    // clears the confirmation (see ProfileController::update) and sends them
    // back to the code screen.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    /*
    |----------------------------------------------------------------
    | MESSAGES — a guest and a resort, in private
    |----------------------------------------------------------------
    |
    | SHARED, not under /tourist or /owner, because both sides use the
    | same four screens and the same authorisation. Splitting them
    | would be two copies of one rule, and the day those drift is the
    | day one of them leaks somebody's correspondence.
    |
    | Who may see what is ConversationPolicy's business, and it admits
    | exactly two people: the guest who opened the thread and whoever
    | owns the resort now. The tourism office is not among them and
    | has no screen here — see the policy for why.
    |
    | Verified, like the rest of the signed-in site: an unconfirmed
    | address should not be able to start writing to a business.
    */
    Route::middleware('verified')->group(function () {
        Route::get('/messages', [ConversationController::class, 'index'])->name('messages.index');

        // Above /messages/{conversation}, or "unread-count" is read as an id.
        Route::get('/messages/unread-count', [ConversationController::class, 'unreadCount'])
            ->middleware('throttle:60,1')
            ->name('messages.unread-count');

        Route::get('/messages/{conversation}', [ConversationController::class, 'show'])->name('messages.show');

        Route::post('/messages/{conversation}', [ConversationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('messages.store');

        // Opens the thread with a resort, or returns the existing one.
        Route::post('/messages/resort/{resort}', [ConversationController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('messages.start');

        /*
        | A picture somebody sent. The files are on the private disk,
        | so this action is the whole of the access control and it
        | authorises against the conversation before reading a byte.
        */
        Route::get('/messages/attachments/{attachment}', MessageAttachmentController::class)
            ->name('messages.attachment');
    });
});

/*
|--------------------------------------------------------------------------
| TOURIST (bookings arrive in Phases 11 and 13)
|--------------------------------------------------------------------------
*/

Route::prefix('tourist')
    ->name('tourist.')
    ->middleware(['auth', 'active', 'verified', 'role:tourist'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Tourist\DashboardController::class)->name('dashboard');

        // Reservations
        Route::get('/bookings', [TouristBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/create', [TouristBookingController::class, 'create'])->name('bookings.create');

        /*
        | What this resort asks for up front, for the figures the guest
        | is looking at. Display only: BookingService prices the stay
        | again and asks the policy again when the request is submitted.
        |
        | ABOVE /bookings/{booking} ON PURPOSE. Below it, Laravel would
        | read "payment-terms-preview" as a booking reference.
        |
        | Restored after it went missing: BookingController still had
        | previewPaymentTerms() and create.blade.php still called
        | route('tourist.bookings.payment-terms-preview'), so with the
        | route gone the booking form threw RouteNotFoundException and
        | returned a 500 for every guest.
        */
        Route::post('/bookings/payment-terms-preview', [TouristBookingController::class, 'previewPaymentTerms'])
            ->middleware('throttle:60,1')
            ->name('bookings.payment-terms-preview');

        Route::post('/bookings', [TouristBookingController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('bookings.store');
        Route::get('/bookings/{booking}', [TouristBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}/cancel', [TouristBookingController::class, 'cancel'])->name('bookings.cancel');

        // Paying for a confirmed reservation. The gateway takes the payment on
        // its own pages; these routes only open a checkout and read the answer.
        Route::post('/bookings/{booking}/pay', [TouristPaymentController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('payments.start');
        Route::get('/bookings/{booking}/pay/return', [TouristPaymentController::class, 'return'])->name('payments.return');
        Route::get('/bookings/{booking}/pay/cancel', [TouristPaymentController::class, 'cancel'])->name('payments.cancel');

        Route::post('/bookings/{booking}/manual-pay/start', [ManualPaymentController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('manual-payments.start');
        Route::get('/bookings/{booking}/manual-pay', [ManualPaymentController::class, 'show'])->name('manual-payments.show');
        Route::post('/bookings/{booking}/manual-pay/submit', [ManualPaymentController::class, 'submit'])
            ->middleware('throttle:20,1')
            ->name('manual-payments.submit');

        // Reviewing a finished stay. The booking is the subject, because only
        // a completed booking entitles anyone to write one (see ReviewPolicy).
        Route::get('/bookings/{booking}/review', [TouristReviewController::class, 'create'])->name('reviews.create');
        Route::post('/bookings/{booking}/review', [TouristReviewController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('reviews.store');
        Route::get('/bookings/{booking}/review/edit', [TouristReviewController::class, 'edit'])->name('reviews.edit');
        Route::put('/bookings/{booking}/review', [TouristReviewController::class, 'update'])->name('reviews.update');
    });

/*
|--------------------------------------------------------------------------
| RESORT OWNER (resort, rooms, and bookings arrive in Phases 6, 8, and 12)
|--------------------------------------------------------------------------
*/

Route::prefix('owner')
    ->name('owner.')
    ->middleware(['auth', 'active', 'verified', 'role:resort_owner'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Owner\DashboardController::class)->name('dashboard');

        // The owner's own resort. No resort id appears in these URLs.
        Route::get('/resort', [OwnerResortController::class, 'show'])->name('resort.show');
        Route::get('/resort/create', [OwnerResortController::class, 'create'])->name('resort.create');
        Route::post('/resort', [OwnerResortController::class, 'store'])->name('resort.store');
        Route::get('/resort/edit', [OwnerResortController::class, 'edit'])->name('resort.edit');
        Route::put('/resort', [OwnerResortController::class, 'update'])->name('resort.update');
        Route::patch('/resort/resubmit', [OwnerResortController::class, 'resubmit'])->name('resort.resubmit');

        // Each amenity belongs to this owner's resort. Controller checks
        // the resort relationship on every edit/toggle/delete request.
        Route::get('/amenities', [OwnerAmenityController::class, 'index'])->name('amenities.index');
        Route::post('/amenities', [OwnerAmenityController::class, 'store'])
            ->middleware('throttle:20,1')->name('amenities.store');
        Route::put('/amenities/{amenity}', [OwnerAmenityController::class, 'update'])
            ->name('amenities.update');
        Route::patch('/amenities/{amenity}/status', [OwnerAmenityController::class, 'toggle'])
            ->name('amenities.toggle');
        Route::delete('/amenities/{amenity}', [OwnerAmenityController::class, 'destroy'])
            ->name('amenities.destroy');

        // Room types
        /*
        | What the resort offers besides the room: kayak hire, a banana
        | boat ride, island hopping.
        |
        | Guests ASK for these rather than buy them — whether a boat
        | goes out depends on the weather and the boatman — so nothing
        | here reaches a booking total. See BookingExtraService.
        */
        Route::get('/extras', [OwnerExtraController::class, 'index'])->name('extras.index');
        Route::get('/extras/new', [OwnerExtraController::class, 'create'])->name('extras.create');
        Route::post('/extras', [OwnerExtraController::class, 'store'])->name('extras.store');
        Route::get('/extras/{extra}/edit', [OwnerExtraController::class, 'edit'])->name('extras.edit');
        Route::put('/extras/{extra}', [OwnerExtraController::class, 'update'])->name('extras.update');
        Route::patch('/extras/{extra}/status', [OwnerExtraController::class, 'status'])->name('extras.status');

        // Answering one guest's request, yes or no.
        Route::patch('/booking-extras/{extra}', [OwnerBookingExtraController::class, 'answer'])
            ->name('booking-extras.answer');

        Route::get('/rooms', [OwnerRoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/create', [OwnerRoomController::class, 'create'])->name('rooms.create');
        Route::post('/rooms', [OwnerRoomController::class, 'store'])->name('rooms.store');
        Route::get('/rooms/{room}/edit', [OwnerRoomController::class, 'edit'])->name('rooms.edit');
        Route::put('/rooms/{room}', [OwnerRoomController::class, 'update'])->name('rooms.update');
        Route::patch('/rooms/{room}/status', [OwnerRoomController::class, 'status'])->name('rooms.status');
        Route::delete('/rooms/{room}', [OwnerRoomController::class, 'destroy'])->name('rooms.destroy');

        Route::post('/rooms/{room}/images', [OwnerRoomImageController::class, 'store'])->name('rooms.images.store');
        Route::delete('/rooms/{room}/images/{image}', [OwnerRoomImageController::class, 'destroy'])
            ->scopeBindings()
            ->name('rooms.images.destroy');

        // Reservations
        Route::get('/bookings', [OwnerBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking}', [OwnerBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}/confirm', [OwnerBookingController::class, 'confirm'])->name('bookings.confirm');
        Route::patch('/bookings/{booking}/reject', [OwnerBookingController::class, 'reject'])->name('bookings.reject');
        Route::patch('/bookings/{booking}/complete', [OwnerBookingController::class, 'complete'])->name('bookings.complete');
        Route::patch('/bookings/{booking}/cancel', [OwnerBookingController::class, 'cancel'])->name('bookings.cancel');

        Route::get('/availability', OwnerAvailabilityController::class)->name('availability');
        Route::get('/statistics', OwnerStatisticsController::class)->name('statistics');

        Route::get('/gcash', [ManualGcashController::class, 'edit'])->name('manual-gcash.edit');
        Route::put('/gcash', [ManualGcashController::class, 'update'])->name('manual-gcash.update');

        /*
        | What this resort asks for up front, and by when. The owner's own
        | commercial terms, so the office has no route into them.
        */
        Route::get('/payment-policy', [PaymentPolicyController::class, 'edit'])->name('payment-policy.edit');
        Route::put('/payment-policy', [PaymentPolicyController::class, 'update'])->name('payment-policy.update');
        Route::delete('/payment-policy', [PaymentPolicyController::class, 'destroy'])->name('payment-policy.destroy');

        Route::get('/payments/waiting', [PaymentProofController::class, 'index'])->name('payment-proofs.index');
        Route::post('/payments/{proof}/approve', [PaymentProofController::class, 'approve'])->name('payment-proofs.approve');
        Route::post('/payments/{proof}/reject', [PaymentProofController::class, 'reject'])->name('payment-proofs.reject');
        Route::get('/payments/{proof}/image', [PaymentProofController::class, 'image'])->name('payment-proofs.image');

        // Guest reviews. An owner may answer one but never remove it.
        Route::get('/reviews', [OwnerReviewController::class, 'index'])->name('reviews.index');
        Route::post('/reviews/{review}/reply', [OwnerReviewController::class, 'reply'])->name('reviews.reply');

        // Gallery photos
        Route::post('/resort/images', [OwnerResortImageController::class, 'store'])->name('resort.images.store');
        Route::patch('/resort/images/{image}/cover', [OwnerResortImageController::class, 'setCover'])->name('resort.images.cover');
        Route::delete('/resort/images/{image}', [OwnerResortImageController::class, 'destroy'])->name('resort.images.destroy');
    });

/*
| The barangay official area stood here: an account tied to one barangay
| that wrote up the places it knew and sent them to the office to
| publish. The role has been removed, and with it the review queue under
| admin/spot-submissions. The Municipal Tourism Office writes up
| destinations itself, under admin/destinations.
|
| Dasol's eighteen barangays are untouched by that: they are still what
| a resort or a destination records as its address, still the layer and
| the information panel on the map, and still what
| config('visitdasol.barangays') lists.
*/

/*
|--------------------------------------------------------------------------
| MUNICIPAL TOURISM OFFICE (reviews and reports arrive in Phases 4, 7, 9, 14-17)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'verified', 'role:super_admin'])
    ->group(function () {
        Route::get('/dashboard', \App\Http\Controllers\Admin\DashboardController::class)->name('dashboard');

        // Resort owner applications
        Route::get('/owner-applications', [OwnerApplicationController::class, 'index'])->name('owner-applications.index');
        Route::get('/owner-applications/{owner}', [OwnerApplicationController::class, 'show'])->name('owner-applications.show');
        Route::get('/owner-applications/{owner}/permit', [OwnerApplicationController::class, 'permit'])->name('owner-applications.permit');
        Route::patch('/owner-applications/{owner}/approve', [OwnerApplicationController::class, 'approve'])->name('owner-applications.approve');
        Route::patch('/owner-applications/{owner}/reject', [OwnerApplicationController::class, 'reject'])->name('owner-applications.reject');
        Route::patch('/owner-applications/{owner}/reopen', [OwnerApplicationController::class, 'reopen'])->name('owner-applications.reopen');

        // Accounts
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
        Route::patch('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');

        Route::get('/owners', [AdminOwnerController::class, 'index'])->name('owners.index');

        Route::get('/map', AdminMapController::class)->name('map');

        /*
        | The municipal environmental fee stood here, with its own
        | prefix: the rate and its dates, the checkpoints, the collector
        | assignments, the municipal GCash account, checking that
        | transfers arrived, counting what the gates handed in, and the
        | staff who did all of it. The whole module has been removed.
        |
        | Nothing of it touched resort money, which is why none of the
        | booking, payment or reporting code below needed changing.
        */

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])->name('activity-logs.index');

        // Reservations across every resort (read only)
        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [AdminReportController::class, 'export'])->name('reports.export');
        // The Department of Tourism's monthly accommodation return, as a
        // spreadsheet the office can file.
        Route::get('/reports/accommodation', [AdminReportController::class, 'accommodation'])
            ->name('reports.accommodation');

        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('bookings.show');

        // Tourist destinations
        Route::get('/tourist-spots', [AdminDestinationController::class, 'index'])->name('destinations.index');
        Route::get('/tourist-spots/create', [AdminDestinationController::class, 'create'])->name('destinations.create');
        Route::post('/tourist-spots', [AdminDestinationController::class, 'store'])->name('destinations.store');
        Route::get('/tourist-spots/{destination}/edit', [AdminDestinationController::class, 'edit'])->name('destinations.edit');
        Route::put('/tourist-spots/{destination}', [AdminDestinationController::class, 'update'])->name('destinations.update');
        Route::patch('/tourist-spots/{destination}/status', [AdminDestinationController::class, 'status'])->name('destinations.status');
        Route::patch('/tourist-spots/{destination}/feature', [AdminDestinationController::class, 'feature'])->name('destinations.feature');
        Route::delete('/tourist-spots/{destination}', [AdminDestinationController::class, 'destroy'])->name('destinations.destroy');

        Route::post('/tourist-spots/{destination}/images', [AdminDestinationImageController::class, 'store'])->name('destinations.images.store');
        Route::patch('/tourist-spots/{destination}/images/{image}/cover', [AdminDestinationImageController::class, 'setCover'])
            ->scopeBindings()->name('destinations.images.cover');
        Route::delete('/tourist-spots/{destination}/images/{image}', [AdminDestinationImageController::class, 'destroy'])
            ->scopeBindings()->name('destinations.images.destroy');

        // Destination categories
        Route::get('/tourist-spot-categories', [AdminDestinationCategoryController::class, 'index'])->name('destination-categories.index');
        Route::post('/tourist-spot-categories', [AdminDestinationCategoryController::class, 'store'])->name('destination-categories.store');
        Route::put('/tourist-spot-categories/{category}', [AdminDestinationCategoryController::class, 'update'])->name('destination-categories.update');
        Route::delete('/tourist-spot-categories/{category}', [AdminDestinationCategoryController::class, 'destroy'])->name('destination-categories.destroy');

        // Announcements
        Route::get('/announcements', [AdminAnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/create', [AdminAnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AdminAnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('/announcements/{announcement}/edit', [AdminAnnouncementController::class, 'edit'])->name('announcements.edit');
        Route::put('/announcements/{announcement}', [AdminAnnouncementController::class, 'update'])->name('announcements.update');
        Route::patch('/announcements/{announcement}/publish', [AdminAnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::patch('/announcements/{announcement}/unpublish', [AdminAnnouncementController::class, 'unpublish'])->name('announcements.unpublish');
        Route::patch('/announcements/{announcement}/feature', [AdminAnnouncementController::class, 'feature'])->name('announcements.feature');
        Route::delete('/announcements/{announcement}', [AdminAnnouncementController::class, 'destroy'])->name('announcements.destroy');

        /*
        | The amenity master list was managed here. The screens have
        | been removed from the office.
        |
        | THE AMENITIES THEMSELVES ARE UNTOUCHED. Resort owners still
        | tick them when describing a resort, visitors still filter by
        | them, and both pages still read the amenities table. What is
        | gone is the only way to add, rename, switch off or delete an
        | entry in that list — it is now whatever AmenitySeeder put
        | there, changeable only in the database.
        */

        // Resort listings waiting for review
        Route::get('/resort-applications', [ResortApplicationController::class, 'index'])->name('resort-applications.index');
        Route::get('/resort-applications/{resort}', [ResortApplicationController::class, 'show'])->name('resort-applications.show');
        Route::patch('/resort-applications/{resort}/approve', [ResortApplicationController::class, 'approve'])->name('resort-applications.approve');
        Route::patch('/resort-applications/{resort}/reject', [ResortApplicationController::class, 'reject'])->name('resort-applications.reject');

        // All resorts
        Route::get('/resorts', [AdminResortController::class, 'index'])->name('resorts.index');
        Route::get('/resorts/{resort}', [AdminResortController::class, 'show'])->name('resorts.show');
        // Review moderation. Hiding never deletes; the row and its reason stay.
        Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::patch('/reviews/{review}/hide', [AdminReviewController::class, 'hide'])->name('reviews.hide');
        Route::patch('/reviews/{review}/restore', [AdminReviewController::class, 'restore'])->name('reviews.restore');

        Route::get('/resorts/{resort}/edit', [AdminResortController::class, 'edit'])->name('resorts.edit');
        Route::put('/resorts/{resort}', [AdminResortController::class, 'update'])->name('resorts.update');
        Route::patch('/resorts/{resort}/activate', [AdminResortController::class, 'activate'])->name('resorts.activate');
        Route::patch('/resorts/{resort}/deactivate', [AdminResortController::class, 'deactivate'])->name('resorts.deactivate');
        Route::patch('/resorts/{resort}/feature', [AdminResortController::class, 'feature'])->name('resorts.feature');

        /*
        | Settings.
        |
        | One route per verb and a {group} segment, because the groups
        | are declared in App\Settings\SettingsRegistry rather than
        | here: adding a group must not mean adding a route. The
        | segment is constrained to the groups that actually exist, so
        | an unknown one is a 404 from the router rather than a
        | half-rendered page.
        |
        | Authorisation is SettingPolicy, checked per group inside the
        | controller. The role:super_admin middleware on this whole
        | group is the outer door; the policy is the one that knows
        | which rooms are open to whom.
        */
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

        Route::get('/settings/{group}', [SettingsController::class, 'edit'])
            ->whereIn('group', array_keys(SettingsRegistry::groups()))
            ->name('settings.edit');

        Route::put('/settings/{group}', [SettingsController::class, 'update'])
            ->whereIn('group', array_keys(SettingsRegistry::groups()))
            ->name('settings.update');

        Route::post('/settings-cache-clear', [SettingsController::class, 'clearCache'])->name('settings.cache-clear');
        Route::post('/settings-mail-test', [SettingsController::class, 'testMail'])->name('settings.mail-test');
    });

/*
|--------------------------------------------------------------------------
| LOCAL DEVELOPMENT ONLY
|--------------------------------------------------------------------------
|
| Registered only when APP_ENV=local. They never exist on a demo or
| production server. Removed entirely in Phase 21.
|
*/

if (app()->environment('local')) {
    Route::prefix('_dev')->name('dev.')->group(function () {
        // Runs without sessions so it still works when the database is misconfigured
        // (sessions are stored in the database).
        Route::get('/setup', SetupCheckController::class)
            ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
            ->name('setup');

        Route::view('/preview/owner', 'dev.preview-owner')->name('preview.owner');

        Route::view('/preview/admin', 'dev.preview-admin', [
            'pendingOwnerCount' => 3,
            'pendingResortCount' => 2,
        ])->name('preview.admin');

        Route::get('/preview/error/{code}', fn (int $code) => abort($code))
            ->whereIn('code', ['403', '404', '419', '500', '503'])
            ->name('preview.error');
    });
}
