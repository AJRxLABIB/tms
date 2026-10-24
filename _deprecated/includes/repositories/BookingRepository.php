<?php
/**
 * BookingRepository — the project's first Repository-layer class (Rule
 * 40/71: architecture). This does NOT rewrite every booking query in the
 * app (Rule 1: never rewrite from scratch) — it's a demonstrated,
 * incrementally-adoptable pattern: one core entity (tblbooking), wired
 * into one real call site (frontend/tour-history.php) with behavior kept
 * byte-for-byte identical to what that page already did inline. Other
 * booking call sites (admin/manage-bookings.php, frontend/ticket.php,
 * frontend/package-details.php, etc.) are untouched and keep their own
 * inline SQL — they can adopt this repository later, the same way
 * PaymentService (backend/payments/PaymentService.php) was adopted by
 * checkout.php/payment.php without every payment-adjacent file switching
 * over at once.
 *
 * Style deliberately mirrors PaymentService: no namespace, a constructor-
 * injected PDO, typed properties — not a new convention invented for this
 * one class.
 */
class BookingRepository
{
    private PDO $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * All bookings for a customer, newest first, joined to the package
     * name — the exact result shape frontend/tour-history.php has always
     * queried and rendered. Returns stdClass rows (PDO::FETCH_OBJ) so
     * existing template code ($b->BookingId, $b->PaymentStatus, ...) needs
     * no changes at the call site.
     *
     * RESILIENCE (Rule 2, carried over verbatim from the inline version
     * this replaces): b.PaymentStatus was added by
     * database/migrations/003_booking_payment_columns.sql. If that
     * migration hasn't run yet on a given database, selecting it would
     * throw (PDO is configured ERRMODE_EXCEPTION) and take this whole page
     * down for every visitor — so this tries the new column first and
     * falls back to the pre-payment column list, identically to what was
     * inline in frontend/tour-history.php before this refactor.
     */
    public function findByUserEmail(string $email): array
    {
        $sqlWithPayment = "SELECT b.BookingId, b.PackageId, p.PackageName, b.FromDate, b.ToDate,
               b.Comment, b.status, b.RegDate, b.CancelledBy, b.UpdationDate,
               b.NoOfPersons, b.TotalCost, b.VerificationCode,
               b.RequestedFromDate, b.RequestedToDate, b.ModificationStatus, b.ModificationNote,
               b.RefundStatus, b.RefundNote, b.PaymentStatus
        FROM tblbooking b
        JOIN tbltourpackages p ON p.PackageId = b.PackageId
        WHERE b.UserEmail=:uemail
        ORDER BY b.BookingId DESC";
        $sqlWithoutPayment = "SELECT b.BookingId, b.PackageId, p.PackageName, b.FromDate, b.ToDate,
               b.Comment, b.status, b.RegDate, b.CancelledBy, b.UpdationDate,
               b.NoOfPersons, b.TotalCost, b.VerificationCode,
               b.RequestedFromDate, b.RequestedToDate, b.ModificationStatus, b.ModificationNote,
               b.RefundStatus, b.RefundNote
        FROM tblbooking b
        JOIN tbltourpackages p ON p.PackageId = b.PackageId
        WHERE b.UserEmail=:uemail
        ORDER BY b.BookingId DESC";

        try {
            $q = $this->dbh->prepare($sqlWithPayment);
            $q->bindParam(':uemail', $email, PDO::PARAM_STR);
            $q->execute();
        } catch (PDOException $e) {
            error_log('[TMS] BookingRepository::findByUserEmail: PaymentStatus column not available yet (run database/migrate.php) -- falling back: ' . $e->getMessage());
            $q = $this->dbh->prepare($sqlWithoutPayment);
            $q->bindParam(':uemail', $email, PDO::PARAM_STR);
            $q->execute();
        }

        return $q->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * A single booking, scoped to its owning customer and excluding
     * already-cancelled rows (status != 2) — the same ownership+state
     * guard frontend/tour-history.php has always checked before letting a
     * customer cancel or request a date change on a booking. Returns null
     * if not found, not owned by this email, or already cancelled.
     */
    public function findActiveForUser(int $bookingId, string $email): ?object
    {
        $chk = $this->dbh->prepare(
            "SELECT * FROM tblbooking WHERE BookingId=:bid AND UserEmail=:email AND status != 2 LIMIT 1"
        );
        $chk->bindParam(':bid', $bookingId, PDO::PARAM_INT);
        $chk->bindParam(':email', $email, PDO::PARAM_STR);
        $chk->execute();
        $row = $chk->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /** One booking by id, no ownership/status filter (admin-side lookups). */
    public function findById(int $bookingId): ?object
    {
        $q = $this->dbh->prepare("SELECT * FROM tblbooking WHERE BookingId=:bid LIMIT 1");
        $q->bindParam(':bid', $bookingId, PDO::PARAM_INT);
        $q->execute();
        $row = $q->fetch(PDO::FETCH_OBJ);
        return $row ?: null;
    }
}
