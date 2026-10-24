<?php
/**
 * BookingStateMachine — validates transitions across tblbooking's four
 * independent status fields, confirmed live against every real write
 * site in this codebase (grep across frontend/, admin/, api/,
 * backend/payments/):
 *
 *   status:             0=Pending, 1=Confirmed, 2=Cancelled (terminal)
 *                        -- see database/tms.sql's own column comment.
 *   PaymentStatus:       null/unpaid -> pending -> paid -> refunded |
 *                        partially_refunded (also: pending -> failed,
 *                        listed in the column's own COMMENT but not yet
 *                        wired to any write site -- kept legal here so
 *                        adding that code path later doesn't also require
 *                        touching this file).
 *   RefundStatus:        null -> requested -> processed | rejected.
 *   ModificationStatus:  null -> pending -> approved | rejected.
 *
 * This does NOT change tblbooking's schema or rewrite any existing write
 * site (Rule 1/2/8) -- it's a pure, stateless validation layer, in the
 * same incrementally-adoptable style as BookingRepository
 * (backend/repositories/BookingRepository.php) and PaymentService
 * (backend/payments/PaymentService.php): built once, fully tested
 * (tests/run.php), and wired into call sites one at a time as they're
 * touched, never all at once. A call site that doesn't use this class yet
 * is unaffected by its existence.
 *
 * Every transition table below was built by reading the actual UPDATE
 * statements in this codebase, not designed from scratch -- so a
 * currently-working action is never rejected by these rules. Where no
 * write site exists yet for a value the schema documents (PaymentStatus
 * 'failed'), it is still included as legal so this class stays accurate
 * once that code path is added, per Rule 3 (never invent behavior that
 * doesn't reflect the real system) applied to the *documented* schema
 * contract, not a guess.
 */
class BookingStateMachine
{
    /** @var array<int, int[]> */
    private const STATUS_TRANSITIONS = [
        0 => [0, 1, 2], // Pending   -> Pending (no-op) | Confirmed | Cancelled
        1 => [1, 2],    // Confirmed -> Confirmed (no-op) | Cancelled
        2 => [2],       // Cancelled is terminal -- no resurrecting a booking.
    ];

    /** @var array<string, string[]> */
    private const PAYMENT_STATUS_TRANSITIONS = [
        ''                  => ['unpaid', 'pending'],
        'unpaid'            => ['unpaid', 'pending'],
        'pending'           => ['pending', 'paid', 'failed'],
        'failed'            => ['failed', 'pending'], // retry
        'paid'              => ['paid', 'refunded', 'partially_refunded'],
        'refunded'          => ['refunded'],           // terminal
        'partially_refunded'=> ['partially_refunded', 'refunded'],
    ];

    /** @var array<string, string[]> */
    private const REFUND_STATUS_TRANSITIONS = [
        ''          => ['', 'requested'],
        'requested' => ['requested', 'processed', 'rejected'],
        'processed' => ['processed'], // terminal
        'rejected'  => ['rejected'],  // terminal -- see note below.
    ];

    /** @var array<string, string[]> */
    private const MODIFICATION_STATUS_TRANSITIONS = [
        ''         => ['', 'pending'],
        'pending'  => ['pending', 'approved', 'rejected'],
        'approved' => ['approved'], // terminal
        'rejected' => ['rejected'], // terminal
    ];

    public static function canTransitionStatus(int $from, int $to): bool
    {
        return in_array($to, self::STATUS_TRANSITIONS[$from] ?? [], true);
    }

    public static function canTransitionPaymentStatus(?string $from, string $to): bool
    {
        $from = (string) $from;
        return in_array($to, self::PAYMENT_STATUS_TRANSITIONS[$from] ?? [], true);
    }

    public static function canTransitionRefundStatus(?string $from, string $to): bool
    {
        $from = (string) $from;
        return in_array($to, self::REFUND_STATUS_TRANSITIONS[$from] ?? [], true);
    }

    public static function canTransitionModificationStatus(?string $from, string $to): bool
    {
        $from = (string) $from;
        return in_array($to, self::MODIFICATION_STATUS_TRANSITIONS[$from] ?? [], true);
    }
}
