
/*
 * VisitDasol — Booking Request Form
 *
 * Browser calculations are for display and guidance only.
 * BookingService must recalculate price, availability, capacity,
 * and applicable discounts on the server.
 *
 * Features:
 * - Adults + Children = Total Guests
 * - PWD count validation (PWD guests are included in total guests)
 * - Male/Female guest count guidance
 * - Room capacity and bed count display
 * - Live price summary, child and PWD discounts included
 * - Manual GCash details toggle
 */
(function () {
  'use strict';

  var PESO = '₱';

  function money(centavos) {
    return PESO + ((Number(centavos) || 0) / 100).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  // A rate off a data attribute is in pesos; everything below counts in
  // centavos, as App\Support\Money does.
  function pesos(amount) {
    return money(Math.round((Number(amount) || 0) * 100));
  }

  /*
   * What the guest is buying. Must match App\Enums\BookingType, and the
   * rate keys must match the data attributes on each room option.
   */
  var TYPE_LABELS = {
    overnight: 'Overnight stay',
    day_tour: 'Day tour',
    night_tour: 'Night tour'
  };

  var TYPE_RATES = {
    overnight: 'price',
    day_tour: 'dayTourPrice',
    night_tour: 'nightTourPrice'
  };

  // Zero, including for a blank attribute, means the room is not sold
  // that way at all. It never falls back to the nightly rate.
  function rateFor(option, type) {
    return parseFloat(option.dataset[TYPE_RATES[type] || 'price']) || 0;
  }

  /*
   * The same sums as App\Services\BookingPricer, in the same order and
   * in the same whole centavos, so the figure a guest reads here is the
   * figure the server goes on to charge. Change the two together.
   *
   * A room is priced by the night, so a discount belonging to one guest
   * comes off that guest's share of the room bill. PWD guests are read
   * against the adults first, which leaves the smallest overlap with the
   * children the stated counts allow, and anyone still in both groups is
   * discounted once, at whichever rate is kinder to them.
   */
  function quote(input) {
    var guests = input.adults + input.children;
    var pwdGuests = Math.max(0, Math.min(input.pwdGuests, guests));

    /*
     * A night is sold by the night; a tour is sold by the booking.
     * Multiplying a tour by nights would make it free, because a tour
     * has none.
     */
    var subtotal = input.overnight
      ? Math.round(input.price * 100) * input.nights * input.rooms
      : Math.round(input.price * 100) * input.rooms;

    var pwdAdults = Math.min(pwdGuests, input.adults);
    var pwdChildren = pwdGuests - pwdAdults;
    var plainChildren = Math.max(0, input.children - pwdChildren);

    var childrenDiscounted = plainChildren +
      (input.childPercent >= input.pwdPercent ? pwdChildren : 0);

    var pwdDiscounted = pwdAdults +
      (input.pwdPercent > input.childPercent ? pwdChildren : 0);

    function shareOf(discountedGuests, percent) {
      if (subtotal < 1 || guests < 1 || discountedGuests < 1 || percent < 1) {
        return 0;
      }

      // Divided last and truncated, so a share that does not divide
      // evenly rounds towards the resort and never by more than a centavo.
      return Math.floor(
        (subtotal * Math.min(discountedGuests, guests) * percent) /
        (guests * 100)
      );
    }

    var child = Math.min(subtotal, shareOf(childrenDiscounted, input.childPercent));
    var pwd = Math.min(subtotal - child, shareOf(pwdDiscounted, input.pwdPercent));

    return {
      subtotal: subtotal,
      childDiscount: child,
      pwdDiscount: pwd,
      total: subtotal - child - pwd,
      childPercent: child > 0 ? input.childPercent : 0,
      pwdPercent: pwd > 0 ? input.pwdPercent : 0,
      childrenDiscounted: child > 0 ? childrenDiscounted : 0,
      pwdDiscounted: pwd > 0 ? pwdDiscounted : 0
    };
  }

  function plural(count, word) {
    return count + ' ' + word + (count === 1 ? '' : 's');
  }

  function getNumber(field, fallback) {
    if (!field || field.value === '') {
      return fallback;
    }

    var value = parseInt(field.value, 10);

    return Number.isFinite(value) ? value : fallback;
  }

  function nightsBetween(from, to) {
    if (!from || !to) {
      return 0;
    }

    var start = Date.parse(from + 'T00:00:00');
    var end = Date.parse(to + 'T00:00:00');

    if (isNaN(start) || isNaN(end) || end <= start) {
      return 0;
    }

    return Math.round((end - start) / 86400000);
  }

  /**
   * The day after an ISO date, as an ISO date.
   *
   * BUILT FROM LOCAL PARTS, NOT toISOString(). The obvious version of
   * this — new Date(iso + 'T00:00:00'), add a day, toISOString().slice()
   * — parses as local midnight and then prints in UTC. Eight hours west
   * of here that is still the previous evening, so in Manila the
   * "day after" would come back as the same day, and the minimum would
   * let a guest check out on the morning they arrived.
   */
  function dayAfter(iso) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso || '')) {
      return '';
    }

    var parts = iso.split('-');

    var date = new Date(
      Number(parts[0]),
      Number(parts[1]) - 1,
      Number(parts[2])
    );

    if (isNaN(date.getTime())) {
      return '';
    }

    date.setDate(date.getDate() + 1);

    return date.getFullYear() + '-' +
      pad(date.getMonth() + 1) + '-' +
      pad(date.getDate());
  }

  function pad(number) {
    return (number < 10 ? '0' : '') + number;
  }

  function readableTime(value) {
    if (!/^\d{2}:\d{2}$/.test(value || '')) {
      return '';
    }

    var at = new Date('2000-01-01T' + value + ':00');

    return isNaN(at.getTime())
      ? ''
      : at.toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit'
      });
  }

  // One date, for a tour: it starts and ends on the same day, whatever
  // the engine stores to hold the room.
  function readableDate(value) {
    if (!value) {
      return '';
    }

    var day = new Date(value + 'T00:00:00');

    if (isNaN(day.getTime())) {
      return '';
    }

    return day.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  function readableDates(from, to) {
    if (!from || !to) {
      return '';
    }

    var start = new Date(from + 'T00:00:00');
    var end = new Date(to + 'T00:00:00');

    if (isNaN(start.getTime()) || isNaN(end.getTime())) {
      return '';
    }

    return start.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric'
    }) + ' to ' + end.toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  /*
   * Manual GCash details.
   *
   * Required HTML attributes:
   * data-payment-preference
   * data-manual-gcash-details
   */
  function setUpPaymentPreference(form) {
    var details = form.querySelector(
      '[data-manual-gcash-details]'
    );

    var radios = form.querySelectorAll(
      '[data-payment-preference]'
    );

    if (!details || !radios.length) {
      return;
    }

    function updateGcashDetails() {
      var selected = form.querySelector(
        '[data-payment-preference]:checked'
      );

      var show = !!selected &&
        selected.value === 'manual_gcash';

      details.hidden = !show;

      details.querySelectorAll('[data-gcash-required]')
        .forEach(function (field) {
          field.required = show;
        });
    }

    radios.forEach(function (radio) {
      radio.addEventListener('change', updateGcashDetails);
    });

    updateGcashDetails();
  }

  /*
   * What this resort asks for up front, kept in step with the form.
   *
   * THE BROWSER WORKS OUT NOTHING HERE. It sends the room, the arrival
   * date and the total it is showing, and writes back whatever the
   * server says. No deposit percentage, no deadline and no last-minute
   * window appears in this file — those live in the resort's saved
   * policy, and PaymentPolicyService is the only thing that reads them.
   *
   * The figures it sends are for DISPLAY ONLY. BookingService prices
   * the stay again and asks the policy again when the request is
   * actually submitted, so a total edited in the console changes what
   * this panel says and nothing whatsoever about what is charged.
   */
  function setUpPaymentTermsPreview(form) {
    var url = form.dataset.paymentTermsPreviewUrl;
    var panel = form.querySelector('[data-payment-terms-preview]');

    if (!url || !panel || typeof window.fetch !== 'function') {
      return null;
    }

    var out = {
      total: panel.querySelector('[data-terms-total]'),
      dueLabel: panel.querySelector('[data-terms-due-label]'),
      due: panel.querySelector('[data-terms-due]'),
      balanceLabel: panel.querySelector('[data-terms-balance-label]'),
      balance: panel.querySelector('[data-terms-balance]'),
      nothingLabel: panel.querySelector('[data-terms-nothing-label]'),
      nothing: panel.querySelector('[data-terms-nothing]'),
      sentence: panel.querySelector('[data-terms-sentence]'),
      lastMinute: panel.querySelector('[data-terms-lastminute]'),
      onSite: panel.querySelector('[data-terms-onsite]'),
      error: panel.querySelector('[data-terms-error]')
    };

    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var timer = null;
    var inFlight = null;

    /*
     * Answers can come back in a different order from the questions —
     * a guest changing the party quickly sends several. Each request
     * takes a number and only the newest is allowed to write, so a slow
     * answer to an old question cannot overwrite a fast answer to the
     * current one. The abort is the tidy-up; the counter is the rule.
     */
    var sequence = 0;
    var written = 0;

    function show(element, visible) {
      if (element) {
        element.hidden = !visible;
      }
    }

    function text(element, value) {
      if (element && typeof value === 'string') {
        element.textContent = value;
      }
    }

    function apply(terms) {
      var money = terms.formatted || {};
      var due = Boolean(terms.needs_advance_payment);
      var balance = due && Boolean(terms.has_balance);

      text(out.total, money.total);
      text(out.dueLabel, money.due_label);
      text(out.due, money.due_now);
      text(out.balance, money.balance);
      text(out.sentence, terms.sentence);

      show(out.dueLabel, due);
      show(out.due, due);
      show(out.balanceLabel, balance);
      show(out.balance, balance);

      show(out.nothingLabel, !due);
      show(out.nothing, !due);

      show(out.lastMinute, due && Boolean(terms.last_minute));
      show(out.onSite, !due && Boolean(terms.on_site_allowed));
      show(out.error, false);

      applyPaymentMethods(due, Boolean(terms.on_site_allowed));
    }

    /*
     * THE WAYS OF PAYING, KEPT IN STEP WITH THE TERMS.
     *
     * The server already decides this twice — the form is rendered with
     * only the allowed methods, and BookingService refuses a method the
     * terms do not permit. This is the third place, and the only one
     * that can react while the guest is still filling the form in:
     * moving the dates into the resort's last-minute window turns "no
     * deposit" into "pay in full", and a prepaid option that was
     * rightly absent a moment ago now belongs on screen.
     *
     * HIDING IS NOT ENFORCEMENT, and nothing here pretends otherwise.
     * It keeps the choices honest while they are being made; the server
     * is what makes them stick.
     */
    function applyPaymentMethods(due, onSiteAllowed) {
      var radios = form.querySelectorAll('[data-payment-preference]');

      if (!radios.length) {
        return;
      }

      var chosenWasHidden = false;

      radios.forEach(function (radio) {
        // Prepaid methods need something to collect; paying on arrival
        // needs the resort to accept cash on arrival.
        var allowed = radio.value === 'on_arrival'
          ? onSiteAllowed
          : due;

        // The whole option, label and all, so the text goes with the
        // button rather than leaving a stranded sentence behind.
        var option = radio.closest('label') || radio;

        option.hidden = !allowed;
        radio.disabled = !allowed;

        if (!allowed && radio.checked) {
          radio.checked = false;
          chosenWasHidden = true;
        }
      });

      /*
       * A guest whose choice just disappeared is left with none rather
       * than quietly moved to a method they did not pick — except that
       * leaving nothing selected would send an empty preference, so the
       * first option still standing is taken.
       */
      if (chosenWasHidden) {
        for (var i = 0; i < radios.length; i++) {
          if (!radios[i].disabled) {
            radios[i].checked = true;
            break;
          }
        }

        radios[0].dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    /*
     * A failure leaves the last good figures where they are and says so
     * underneath. Blanking them would turn a moment without a network
     * into a booking form that looks like it has lost the price.
     */
    function failed() {
      panel.setAttribute('aria-busy', 'false');
      show(out.error, true);
    }

    function send(totalCentavos, checkInDate) {
      if (!checkInDate) {
        return;
      }

      var mine = ++sequence;

      if (inFlight) {
        inFlight.abort();
      }

      inFlight = typeof window.AbortController === 'function'
        ? new window.AbortController()
        : null;

      panel.setAttribute('aria-busy', 'true');

      window.fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal: inFlight ? inFlight.signal : undefined,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token
        },
        body: JSON.stringify({
          room: form.querySelector('[data-booking-room-id]')
            ? form.querySelector('[data-booking-room-id]').value
            : null,
          check_in_date: checkInDate,
          total: (totalCentavos / 100).toFixed(2)
        })
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.json();
      }).then(function (terms) {
        // Older than something already on the screen: drop it.
        if (mine < written) {
          return;
        }

        written = mine;
        panel.setAttribute('aria-busy', 'false');
        apply(terms);
      }).catch(function (error) {
        // An abort is this code's own doing, not a failure to report.
        if (error && error.name === 'AbortError') {
          return;
        }

        if (mine < written) {
          return;
        }

        failed();
      });
    }

    /*
     * Waited out rather than sent per keystroke. Typing "12" in the
     * adults box is two changes and one intention.
     */
    return function request(totalCentavos, checkInDate) {
      if (timer) {
        window.clearTimeout(timer);
      }

      timer = window.setTimeout(function () {
        send(totalCentavos, checkInDate);
      }, 350);
    };
  }

  function setUp(form) {
    var roomSelect = form.querySelector(
      '[data-booking-room]'
    );

    var roomId = form.querySelector(
      '[data-booking-room-id]'
    );

    var checkIn = form.querySelector(
      '[data-booking-check-in]'
    );

    var checkOut = form.querySelector(
      '[data-booking-check-out]'
    );

    var roomsField = form.querySelector(
      '[data-booking-rooms]'
    );

    var adultsField = form.querySelector(
      '[data-booking-adults]'
    );

    var childrenField = form.querySelector(
      '[data-booking-children]'
    );

    var pwdField = form.querySelector(
      '[data-booking-pwd]'
    );

    var guestsField = form.querySelector(
      '[data-booking-guests]'
    );

    var maleField = form.querySelector(
      '[data-booking-male]'
    );

    var femaleField = form.querySelector(
      '[data-booking-female]'
    );

    /*
     * Null on a page with no preview panel, or in a browser without
     * fetch. refresh() checks before calling, so the rest of the form
     * works exactly as it did either way.
     */
    var askPaymentTerms = setUpPaymentTermsPreview(form);

    var out = {
      roomName: form.querySelector(
        '[data-booking-room-name]'
      ),
      dates: form.querySelector(
        '[data-booking-dates]'
      ),
      nights: form.querySelector(
        '[data-booking-nights]'
      ),
      working: form.querySelector(
        '[data-booking-working]'
      ),
      subtotal: form.querySelector(
        '[data-booking-subtotal]'
      ),
      total: form.querySelector(
        '[data-booking-total]'
      ),
      childRow: form.querySelector(
        '[data-booking-child-row]'
      ),
      childLabel: form.querySelector(
        '[data-booking-child-label]'
      ),
      childDiscount: form.querySelector(
        '[data-booking-child-discount]'
      ),
      pwdRow: form.querySelector(
        '[data-booking-pwd-row]'
      ),
      pwdLabel: form.querySelector(
        '[data-booking-pwd-label]'
      ),
      pwdDiscount: form.querySelector(
        '[data-booking-pwd-discount]'
      ),
      discountNote: form.querySelector(
        '[data-booking-discount-note]'
      ),
      childAges: form.querySelector(
        '[data-booking-child-ages]'
      ),
      childNote: form.querySelector(
        '[data-booking-child-note]'
      ),
      pwdNote: form.querySelector(
        '[data-booking-pwd-note]'
      ),
      capacity: form.querySelector(
        '[data-booking-capacity]'
      ),
      capacitySummary: form.querySelector(
        '[data-booking-capacity-summary]'
      ),
      beds: form.querySelector(
        '[data-booking-beds]'
      ),
      free: form.querySelector(
        '[data-booking-free]'
      ),
      note: form.querySelector(
        '[data-booking-room-note]'
      ),
      sex: form.querySelector(
        '[data-booking-sex]'
      ),
      typeName: form.querySelector(
        '[data-booking-type-name]'
      ),
      nightsTerm: form.querySelector(
        '[data-booking-nights-term]'
      ),
      nightsValue: form.querySelector(
        '[data-booking-nights-value]'
      ),
      timeInSummary: form.querySelector(
        '[data-booking-check-in-time]'
      ),
      timeOutSummary: form.querySelector(
        '[data-booking-check-out-time]'
      )
    };

    var timeIn = form.querySelector(
      '[data-booking-time-in]'
    );

    var timeOut = form.querySelector(
      '[data-booking-time-out]'
    );

    var typeRadios = Array.prototype.slice.call(
      form.querySelectorAll('[data-booking-type]')
    );

    var typeFieldset = form.querySelector(
      '[data-booking-types]'
    );

    var checkOutField = form.querySelector(
      '[data-booking-checkout-field]'
    );

    setUpPaymentPreference(form);

    if (!roomSelect || !checkIn || !checkOut) {
      return;
    }

    var loadedRoom = roomId
      ? roomId.value
      : roomSelect.value;

    var freeText = out.free
      ? out.free.textContent
      : '';

    /*
     * Calculate total guests.
     * PWD guests are included in Adults + Children,
     * not added as a separate guest category.
     */
    function refreshGuestCounts() {
      var adults = Math.max(
        0,
        getNumber(adultsField, 0)
      );

      var children = Math.max(
        0,
        getNumber(childrenField, 0)
      );

      var totalGuests = adults + children;

      if (adultsField && childrenField && guestsField) {
        guestsField.value = totalGuests;
      }

      var pwdGuests = Math.max(
        0,
        getNumber(pwdField, 0)
      );

      if (pwdField) {
        var invalidPwd = pwdGuests > totalGuests;

        pwdField.setCustomValidity(
          invalidPwd
            ? 'PWD guests cannot exceed total guests.'
            : ''
        );
      }

      var option = roomSelect.options[
        roomSelect.selectedIndex
      ];

      var capacity = option
        ? Math.max(0, parseInt(option.dataset.capacity, 10) || 0)
        : 0;

      var rooms = Math.max(
        1,
        getNumber(roomsField, 1)
      );

      var maxGuests = capacity * rooms;

      if (out.capacitySummary && capacity > 0) {
        out.capacitySummary.textContent =
          capacity + ' guests per room (' +
          maxGuests + ' total)';
      }

      if (guestsField && capacity > 0) {
        var overCapacity = totalGuests > maxGuests;

        guestsField.setCustomValidity(
          overCapacity
            ? 'The selected room count accommodates up to ' +
              maxGuests + ' guests.'
            : ''
        );
      }

      countSexes(totalGuests);
    }

    /*
     * Validate male + female counts against total guests.
     */
    function countSexes(totalGuests) {
      if (!out.sex || !maleField || !femaleField) {
        return;
      }

      var maleBlank = maleField.value === '';
      var femaleBlank = femaleField.value === '';

      var male = Math.max(
        0,
        getNumber(maleField, 0)
      );

      var female = Math.max(
        0,
        getNumber(femaleField, 0)
      );

      var stated = male + female;
      var difference = totalGuests - stated;

      out.sex.hidden = false;

      if (maleBlank && femaleBlank) {
        out.sex.textContent =
          'Split your ' + plural(totalGuests, 'guest') +
          ' between male and female. Put 0 where there are none.';
      } else if (difference > 0) {
        out.sex.textContent =
          plural(difference, 'guest') +
          ' still to account for.';
      } else if (difference < 0) {
        out.sex.textContent =
          'That is ' + plural(-difference, 'guest') +
          ' more than the total of ' +
          plural(totalGuests, 'guest') + '.';
      } else {
        out.sex.textContent =
          'That accounts for all ' +
          plural(totalGuests, 'guest') + '.';
      }

      var mismatch = stated !== totalGuests;

      maleField.setCustomValidity(
        mismatch
          ? 'Male and female counts must add up to total guests.'
          : ''
      );

      femaleField.setCustomValidity(
        mismatch
          ? 'Male and female counts must add up to total guests.'
          : ''
      );
    }

    /*
     * The discount lines, and what the selected room's policy is.
     *
     * A line is shown only once there is something on it: a row
     * reading "Child discount ₱0.00" beside a party with no children
     * tells a guest nothing and reads like a refusal.
     */
    function showDiscounts(bill, option) {
      var childPercent = parseInt(option.dataset.childPercent, 10) || 0;
      var pwdPercent = parseInt(option.dataset.pwdPercent, 10) || 0;
      var ages = option.dataset.childAges || '';

      if (out.childRow) {
        out.childRow.hidden = bill.childDiscount < 1;
      }

      if (out.childDiscount) {
        out.childDiscount.textContent = '−' + money(bill.childDiscount);
      }

      if (out.childLabel) {
        out.childLabel.textContent = 'Child discount (' +
          bill.childPercent + '% off ' +
          plural(bill.childrenDiscounted, 'guest') + ')';
      }

      if (out.pwdRow) {
        out.pwdRow.hidden = bill.pwdDiscount < 1;
      }

      if (out.pwdDiscount) {
        out.pwdDiscount.textContent = '−' + money(bill.pwdDiscount);
      }

      if (out.pwdLabel) {
        out.pwdLabel.textContent = 'PWD discount (' +
          bill.pwdPercent + '% off ' +
          plural(bill.pwdDiscounted, 'guest') + ')';
      }

      // The room's own ages beside the Children box, because the resort
      // checks them at the gate and a guest counting to a different
      // definition is a guest who gets a different bill on arrival.
      if (out.childAges) {
        out.childAges.textContent = ages ? '(' + ages + ')' : '';
      }

      if (out.childNote) {
        out.childNote.textContent = childPercent > 0
          ? 'This room takes ' + childPercent + '% off a child\'s share of the bill.'
          : '';
      }

      if (out.pwdNote) {
        out.pwdNote.textContent = pwdPercent > 0
          ? 'Each one takes ' + pwdPercent + '% off their share of the bill. Bring the ID.'
          : '';
      }

      if (out.discountNote) {
        if (childPercent < 1 && pwdPercent < 1) {
          out.discountNote.textContent =
            'This room type offers no child or PWD discount.';
        } else if (bill.childDiscount < 1 && bill.pwdDiscount < 1) {
          out.discountNote.textContent =
            'Add the children or PWD guests in your party to see the discount.';
        } else if (bill.childDiscount > 0 && bill.pwdDiscount > 0) {
          out.discountNote.textContent =
            'A guest who is both a child and a PWD is counted once, ' +
            'at whichever rate is better for them.';
        } else {
          out.discountNote.textContent =
            'The resort confirms this when it answers your request.';
        }
      }
    }

    /*
     * Which kinds the chosen room is sold as, and at what rate.
     *
     * The guest can change room without leaving the form, and the room
     * they move to may not run tours at all. A kind that room does not
     * run is taken off the screen rather than left there to be refused
     * when they press send — and if it was the one they had chosen, the
     * choice falls back to an overnight stay.
     *
     * Returns the kind in force once that reconciling is done.
     */
    function showBookingTypes(option) {
      if (!typeRadios.length) {
        return 'overnight';
      }

      var offered = 0;
      var fallback = null;

      typeRadios.forEach(function (radio) {
        var rate = rateFor(option, radio.value);
        var runs = rate > 0;

        var label = radio.closest('[data-booking-type-option]');
        var price = label
          ? label.querySelector('[data-booking-type-price]')
          : null;

        radio.disabled = !runs;

        if (label) {
          label.hidden = !runs;
        }

        if (price) {
          price.textContent = runs
            ? '— ' + pesos(rate) +
              (radio.value === 'overnight' ? ' per night' : '')
            : '';
        }

        if (runs) {
          offered += 1;
          fallback = fallback || radio;
        }
      });

      var chosen = form.querySelector('[data-booking-type]:checked');

      if ((!chosen || chosen.disabled) && fallback) {
        fallback.checked = true;
        chosen = fallback;
      }

      // Nothing to choose between: don't ask the question.
      if (typeFieldset) {
        typeFieldset.hidden = offered < 2;
      }

      return chosen && !chosen.disabled ? chosen.value : 'overnight';
    }

    /*
     * Update room information and price summary.
     */
    function refresh() {
      var option = roomSelect.options[
        roomSelect.selectedIndex
      ];

      if (!option) {
        return;
      }

      var type = showBookingTypes(option);
      var overnight = type === 'overnight';

      var price = rateFor(option, type);

      var capacity = parseInt(
        option.dataset.capacity,
        10
      ) || 0;

      var beds = parseInt(
        option.dataset.beds,
        10
      ) || 0;

      var rooms = Math.max(
        1,
        getNumber(roomsField, 1)
      );

      /*
       * THE EARLIEST CHECK-OUT THE PICKER WILL OFFER.
       *
       * The server has always refused a check-out on or before the
       * check-in ("after:check_in_date" in StoreBookingRequest), but
       * the date box did not: its minimum was written once, when the
       * page was built, as tomorrow. A guest arriving in March could
       * pick a check-out in January, fill in the rest of the form,
       * send it, and only then be told. Moving the floor with the
       * arrival date makes the picker agree with the rule.
       *
       * THIS RUNS BEFORE THE NIGHTS ARE COUNTED, deliberately. When a
       * date is corrected below, the count and every peso that
       * follows from it have to be worked out from the corrected
       * date — doing this afterwards would leave the price one
       * refresh behind the dates on screen.
       *
       * ONLY FOR OVERNIGHT STAYS. A tour has one date; its check-out
       * box is folded away and the server derives the day after
       * itself, so a minimum here would constrain a field the guest
       * cannot see.
       */
      if (overnight) {
        var floor = dayAfter(checkIn.value);

        if (floor) {
          checkOut.min = floor;

          // A date already below the new floor is corrected rather
          // than left for the browser to reject on submit.
          if (!checkOut.value || checkOut.value < floor) {
            checkOut.value = floor;
          }
        }
      } else {
        /*
         * Cleared, not left behind. A stale minimum on a hidden,
         * no-longer-required box is exactly the kind of thing that
         * makes a form refuse to submit with nothing on screen to
         * explain why.
         */
        checkOut.removeAttribute('min');
      }

      /*
       * A tour has none, whatever is sitting in the folded-away
       * check-out box. The server says the same: nights = 0 for a
       * tour, and the day after is only how the room is held.
       */
      var nights = overnight
        ? nightsBetween(checkIn.value, checkOut.value)
        : 0;

      /*
       * A date the guest cannot see is a date they cannot correct, so
       * the browser must not refuse to send the form over one.
       */
      if (checkOutField) {
        checkOutField.hidden = !overnight;
      }

      checkOut.required = overnight;

      var bill = quote({
        price: price,
        nights: nights,
        rooms: rooms,
        overnight: overnight,
        adults: Math.max(0, getNumber(adultsField, 0)),
        children: Math.max(0, getNumber(childrenField, 0)),
        pwdGuests: Math.max(0, getNumber(pwdField, 0)),
        childPercent: parseInt(option.dataset.childPercent, 10) || 0,
        pwdPercent: parseInt(option.dataset.pwdPercent, 10) || 0
      });

      if (roomId) {
        roomId.value = roomSelect.value;
      }

      if (out.roomName) {
        out.roomName.textContent =
          option.textContent.split('—')[0].trim();
      }

      if (out.typeName) {
        out.typeName.textContent =
          TYPE_LABELS[type] || TYPE_LABELS.overnight;
      }

      if (out.timeInSummary && timeIn) {
        out.timeInSummary.textContent =
          readableTime(timeIn.value) || out.timeInSummary.textContent;
      }

      if (out.timeOutSummary && timeOut) {
        out.timeOutSummary.textContent =
          readableTime(timeOut.value) || out.timeOutSummary.textContent;
      }

      if (out.dates) {
        out.dates.textContent = (overnight
          ? readableDates(checkIn.value, checkOut.value)
          : readableDate(checkIn.value)) ||
          (overnight ? 'Choose your dates' : 'Choose your date');
      }

      if (out.nights) {
        out.nights.textContent = nights;
      }

      // Hidden rather than zeroed: "0 nights" beside a day tour reads
      // like the form lost the booking.
      if (out.nightsTerm) {
        out.nightsTerm.hidden = !overnight;
      }

      if (out.nightsValue) {
        out.nightsValue.hidden = !overnight;
      }

      if (out.working) {
        if (!overnight) {
          out.working.textContent =
            pesos(price) + ' x ' + plural(rooms, 'room');
        } else if (nights > 0) {
          out.working.textContent =
            pesos(price) + ' x ' +
            plural(nights, 'night') + ' x ' +
            plural(rooms, 'room');
        } else {
          out.working.textContent = pesos(price) + ' per night';
        }
      }

      if (out.subtotal) {
        out.subtotal.textContent = money(bill.subtotal);
      }

      if (out.total) {
        out.total.textContent = money(bill.total);
      }

      /*
       * Ask the server what this resort wants up front for the figure
       * just worked out. Here rather than on each field's own handler
       * so it follows every change at once — room, dates, kind, rooms,
       * adults, children, PWD guests — and uses the same total the
       * guest can see above it.
       */
      if (askPaymentTerms) {
        askPaymentTerms(bill.total, checkIn ? checkIn.value : null);
      }

      showDiscounts(bill, option);

      if (out.beds) {
        // The beds the party actually gets, not the beds in one room.
        out.beds.textContent = beds > 0
          ? beds * rooms
          : 'Not specified';
      }

      if (out.capacity && capacity > 0) {
        out.capacity.textContent =
          'Up to ' + capacity + ' per room; ' +
          plural(capacity * rooms, 'guest') + ' total.';
      }

      // Do not reuse availability for a different room type.
      var switched = roomSelect.value !== loadedRoom;

      if (out.free) {
        out.free.hidden = switched;

        if (!switched) {
          out.free.textContent = freeText;
        }
      }

      if (out.note) {
        out.note.hidden = !switched;
      }

      refreshGuestCounts();
    }

    /*
     * Choosing a kind brings its hours with it. Only on an actual change
     * of the choice, so a guest who then sets their own arrival time
     * keeps it — a tour's hours are a sensible start, not a rule.
     */
    typeRadios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (!radio.checked) {
          return;
        }

        if (timeIn && radio.dataset.hoursIn) {
          timeIn.value = radio.dataset.hoursIn;
        }

        if (timeOut && radio.dataset.hoursOut) {
          timeOut.value = radio.dataset.hoursOut;
        }
      });
    });

    typeRadios.concat([
      roomSelect,
      timeIn,
      timeOut,
      checkIn,
      checkOut,
      roomsField,
      adultsField,
      childrenField,
      pwdField,
      guestsField,
      maleField,
      femaleField
    ]).forEach(function (field) {
      if (field) {
        field.addEventListener('change', refresh);
        field.addEventListener('input', refresh);
      }
    });

    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll(
      '[data-booking-form]'
    ).forEach(setUp);
  });
})();
