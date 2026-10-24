<?php
/**
 * TripReadinessScore.php — "TMS V2" prompt, Trip Readiness Score.
 *
 * Confirmed absent anywhere in this codebase before this (checked
 * frontend/my-trip.php, admin/ai-dashboard.php, and a project-wide grep
 * for "readiness" -- nothing). A single, honest percentage summarizing
 * how ready a confirmed booking is for departure, built entirely from
 * real facts already tracked elsewhere in the app -- never a guessed or
 * AI-invented number (Rule 3):
 *
 *   - Payment settled       (40%) -- tblbooking.PaymentStatus === 'paid'
 *   - Booking confirmed     (20%) -- tblbooking.status === 1
 *   - A travel document is on file (20%) -- at least one row in
 *     tbltraveldocuments for this user. Documents in this schema are
 *     per-account, not per-booking (no BookingId column on
 *     tbltraveldocuments), so this checks "has the traveler uploaded
 *     anything to their wallet at all" rather than "the right document
 *     for this specific trip" -- an honest limitation, not fabricated
 *     precision.
 *   - An AI itinerary exists for this booking (20%) -- 
 *     ItineraryPlanner::getByBookingId() returns a non-empty result.
 *
 * Split into a pure computeScore() (no DB, fully unit-testable) and a
 * thin forBooking() wrapper that gathers the four facts and calls it --
 * same shape as BookingStateMachine's stateless design.
 */
class TripReadinessScore
{
    /**
     * @param array{paid: bool, confirmed: bool, hasDocument: bool, hasItinerary: bool} $facts
     * @return array{score: int, components: array<array{key: string, label: string, weight: int, met: bool}>, missing: array<array{key: string, label: string, action_label: string, action_url: string|null}>}
     */
    public static function computeScore(array $facts): array
    {
        $components = [
            ['key' => 'paid',        'label' => 'Payment settled',            'weight' => 40, 'met' => !empty($facts['paid'])],
            ['key' => 'confirmed',   'label' => 'Booking confirmed',          'weight' => 20, 'met' => !empty($facts['confirmed'])],
            ['key' => 'document',    'label' => 'Travel document on file',    'weight' => 20, 'met' => !empty($facts['hasDocument'])],
            ['key' => 'itinerary',   'label' => 'Trip itinerary generated',   'weight' => 20, 'met' => !empty($facts['hasItinerary'])],
        ];

        $score = 0;
        $missing = [];
        foreach ($components as $c) {
            if ($c['met']) {
                $score += $c['weight'];
            } else {
                // 'key' (added for i18n, Round 122) lets a caller translate the
                // label/action_label through its own dictionary; the English
                // strings stay the canonical API/test values.
                $missing[] = ['key' => $c['key']] + match ($c['key']) {
                    'paid'      => ['label' => $c['label'], 'action_label' => 'Complete payment',        'action_url' => null], // no single generic payment URL -- caller fills this in from its own booking id
                    'confirmed' => ['label' => $c['label'], 'action_label' => 'Awaiting team confirmation', 'action_url' => null],
                    'document'  => ['label' => $c['label'], 'action_label' => 'Upload a document',       'action_url' => 'wallet.php'],
                    'itinerary' => ['label' => $c['label'], 'action_label' => 'Generate itinerary',       'action_url' => null], // triggered by an on-page button, not a link
                };
            }
        }

        return ['score' => $score, 'components' => $components, 'missing' => $missing];
    }

    /**
     * @param object $booking Row with ->PaymentStatus and ->status (the
     *                        same object my-trip.php already fetches --
     *                        no extra booking query needed).
     */
    public static function forBooking(PDO $dbh, object $booking, string $email): array
    {
        $paid = isset($booking->PaymentStatus) && $booking->PaymentStatus === 'paid';
        $confirmed = isset($booking->status) && (int)$booking->status === 1;

        $hasDocument = false;
        try {
            $q = $dbh->prepare("SELECT 1 FROM tbltraveldocuments WHERE UserEmail = :email LIMIT 1");
            $q->execute([':email' => $email]);
            $hasDocument = (bool) $q->fetchColumn();
        } catch (Exception $e) {
            error_log('[TMS] TripReadinessScore::forBooking document check failed: ' . $e->getMessage());
        }

        $hasItinerary = false;
        try {
            $q = $dbh->prepare("SELECT 1 FROM tbl_ai_itineraries WHERE booking_id = :bid LIMIT 1");
            $q->execute([':bid' => $booking->BookingId ?? 0]);
            $hasItinerary = (bool) $q->fetchColumn();
        } catch (Exception $e) {
            error_log('[TMS] TripReadinessScore::forBooking itinerary check failed: ' . $e->getMessage());
        }

        return self::computeScore([
            'paid'         => $paid,
            'confirmed'    => $confirmed,
            'hasDocument'  => $hasDocument,
            'hasItinerary' => $hasItinerary,
        ]);
    }
}
