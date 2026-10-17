<?php
/**
 * BookingReadiness — READ-ONLY "can this trip be booked as asked, and what will it cost on that date?"
 *
 * It applies the SAME rules the real booking form enforces (published + priced package, 1-20 travellers, departure in the
 * future, return after departure, supplier blackout dates, remaining seats) using the SAME functions
 * (tms_remaining_seats, tms_dynamic_price, SupplierService::blackoutConflict). It never creates, holds or changes a booking,
 * coupon or seat, and it never takes payment: the traveller still completes the booking on package-details.php.
 *
 * Honest limits: seats are a live read, not a reservation (someone else can take them before the traveller books);
 * the price excludes coupons and add-ons; without an end date the blackout check is reported as "not_checked".
 */
final class BookingReadiness
{
    public const MAX_TRAVELERS = 20; // package-details.php: tms_int($_POST['persons'], 1, 20)

    /** @return array<string,mixed> */
    public static function check(PDO $dbh, array $pkg, int $travelers, ?string $start, ?string $end, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $id = (int)$pkg['id']; $blockers = []; $checks = [];

        if ($travelers > self::MAX_TRAVELERS) $blockers[] = ['code' => 'too_many_travelers', 'detail' => 'The booking form accepts at most ' . self::MAX_TRAVELERS . ' travellers per booking; larger groups should contact support.'];

        $price = (int)($pkg['price'] ?? 0);
        if ($price <= 0) $blockers[] = ['code' => 'no_price', 'detail' => 'This package has no price set, so it cannot be booked online.'];

        $from = self::date($start); $to = self::date($end);
        $dateState = 'not_provided';
        if ($start !== null && $start !== '') {
            if (!$from) { $blockers[] = ['code' => 'invalid_start_date', 'detail' => 'The departure date is not a valid YYYY-MM-DD date.']; $dateState = 'invalid'; }
            elseif ($from <= $now) { $blockers[] = ['code' => 'start_not_in_future', 'detail' => 'The departure date must be in the future.']; $dateState = 'past'; }
            else $dateState = 'ok';
        }
        if ($end !== null && $end !== '') {
            if (!$to) $blockers[] = ['code' => 'invalid_end_date', 'detail' => 'The return date is not a valid YYYY-MM-DD date.'];
            elseif ($from && $to <= $from) $blockers[] = ['code' => 'end_not_after_start', 'detail' => 'The return date must be after the departure date.'];
        }

        // Supplier blackout dates: only when both dates are valid and ordered (otherwise the answer would be a guess).
        $blackout = 'not_checked';
        if ($from && $to && $to > $from) {
            if (!class_exists('SupplierService')) { $f = __DIR__ . '/../services/SupplierService.php'; if (is_file($f)) require_once $f; }
            if (class_exists('SupplierService')) {
                $blackout = SupplierService::blackoutConflict($dbh, $id, $from->format('Y-m-d'), $to->format('Y-m-d')) === null ? 'clear' : 'conflict';
                if ($blackout === 'conflict') $blockers[] = ['code' => 'blackout_dates', 'detail' => 'The supplier is not available for some of those dates.'];
            }
        }

        $seats = function_exists('tms_remaining_seats') ? tms_remaining_seats($dbh, $id) : null;
        if ($seats !== null && $travelers > $seats) $blockers[] = ['code' => 'not_enough_seats', 'detail' => $seats === 0 ? 'No seats are left.' : "Only $seats seat" . ($seats === 1 ? '' : 's') . ' left for ' . $travelers . ' travellers.'];

        $out = ['package' => $pkg['title'], 'package_id' => $id, 'travelers' => $travelers,
            'can_book_as_asked' => !$blockers, 'blockers' => $blockers,
            'seats_remaining' => $seats, 'seats_note' => $seats === null ? 'This package does not track seat capacity.' : 'Live count, not a reservation.',
            'dates' => ['start' => $from ? $from->format('Y-m-d') : null, 'end' => $to ? $to->format('Y-m-d') : null, 'start_state' => $dateState, 'supplier_blackout' => $blackout],
            'cancellation_policy_recorded' => trim((string)($pkg['cancellation'] ?? '')) !== '',
            'citation' => $pkg['citation'] ?? null];

        if ($price > 0) {
            $unit = $price; $label = 'list price';
            if ($dateState === 'ok' && function_exists('tms_dynamic_price')) {
                try { $dp = tms_dynamic_price($dbh, $id, (float)$price, $from->format('Y-m-d')); $unit = (int)$dp['final']; $label = 'price for the departure date' . (!empty($dp['label']) ? ' (' . $dp['label'] . ')' : ''); }
                catch (Throwable $e) { error_log('[TMS AI booking] price: ' . $e->getMessage()); }
            }
            $out['price'] = ['per_person_bdt' => $unit, 'basis' => $label, 'list_price_per_person_bdt' => $price, 'estimated_total_bdt' => $unit * $travelers,
                'excludes' => 'coupons, add-ons and any fees shown at checkout; the final price is confirmed on the booking page'];
        }
        $out['to_book_you_need'] = ['a signed-in account', 'departure and return dates', 'number of travellers', 'a mobile number (optional on the form)'];
        $out['next_step'] = 'Book on the package page (' . ($pkg['citation']['url'] ?? 'package-details.php?pkgid=' . $id) . '). This check reserved nothing.';
        $out['instruction'] = 'Report blockers exactly. Never say a booking was made or seats were held. Say seats can change before the traveller books. Quote prices exactly and mention what they exclude.';
        return $out;
    }

    private static function date(?string $s): ?DateTimeImmutable
    {
        if ($s === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $d : null;
    }
}
