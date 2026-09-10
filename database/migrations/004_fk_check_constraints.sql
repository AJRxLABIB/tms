-- 004_fk_check_constraints.sql
-- Master prompt spec item 38: DB-level FK/CHECK constraints.
--
-- Until this migration, every parent/child relationship in this schema
-- (bookings -> users/packages, payments -> bookings, etc.) was enforced
-- ONLY in application code -- nothing stopped a direct SQL statement, a
-- bug, or a future developer from inserting an orphaned row. This
-- migration closes that gap for the relationships that matter most
-- (booking/payment/financial integrity), following an audit (Round 20)
-- of every candidate relationship for: storage engine (InnoDB required),
-- column type/collation compatibility, and existing orphaned rows.
--
-- Two genuine, pre-existing data-integrity gaps were found and are fixed
-- here BEFORE the constraints that depend on them, rather than skipped or
-- papered over (Rule 3/8):
--
--   1. tblusers.EmailId had no real UNIQUE constraint -- only two
--      redundant plain (non-unique) indexes named `EmailId`/`EmailId_2`,
--      despite register.php relying entirely on an application-level
--      SELECT-then-INSERT check to prevent duplicate accounts (a real
--      TOCTOU race between two near-simultaneous registrations with the
--      same email). No duplicate emails currently exist, so this is safe
--      to add now. The two old non-unique indexes are left in place
--      (harmless redundancy) rather than dropped, since dropping indexes
--      is out of scope for this migration and not what item 38 asked for.
--
--   2. tblbooking.UserEmail, tblissues.UserEmail, and tblenquiry.EmailId
--      each had a small number of rows (2, 2, and 2 respectively) whose
--      email doesn't exist in tblusers at all: 'test@gmail.com',
--      'Jai@gmail.com', 'jone@gmail.com'. All are dated 2020-07-08 --
--      the ORIGINAL tms.sql seed/demo data, years before this engagement,
--      not anything from recent testing. Rather than delete that booking/
--      issue/enquiry history (Rule 2) or silently skip the constraint,
--      three clearly-labeled placeholder tblusers rows are backfilled so
--      the existing rows have something real to point at. This is a
--      referential-integrity backfill for pre-existing internal demo
--      data, not fabrication of a real-world fact (Rule 3): the placeholder
--      accounts are explicitly named as such and have an unusable locked
--      password, so they can never be logged into.
--
-- Several UserEmail columns (tblwishlist, tbltraveldocuments, tblreviews,
-- tblloyalty, tblloyalty_log, tblcart) were created under utf8/utf8mb4
-- collations while tblusers.EmailId is latin1_swedish_ci -- InnoDB
-- requires a matching character set to add a FK, so those columns are
-- converted first. Verified beforehand that none of those columns contain
-- any non-ASCII/non-latin1-safe byte in any row, so no data is altered in
-- a way that could mangle it (emails are ASCII in practice).
--
-- ON DELETE RESTRICT is used everywhere a parent row (a user, a package, a
-- booking, a payment) should never disappear out from under history that
-- references it -- reinforcing, at the DB level, the same anonymize-not-
-- delete design tms_anonymize_user() already implements in application
-- code (see includes/config.php). ON UPDATE CASCADE means an update to a
-- parent key (e.g. tms_anonymize_user()'s own UPDATE of tblusers.EmailId)
-- automatically propagates to every child table below, which only makes
-- that existing function's manual per-table update loop redundant (safe,
-- not harmful) rather than conflicting with it.

-- ── Step 1: backfill placeholder parent rows for pre-existing orphan data ──

INSERT IGNORE INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate)
VALUES ('Legacy Demo Account (backfilled placeholder -- original 2020 seed data, never a real registration, login permanently disabled)', NULL, 'test@gmail.com', MD5(CONCAT('locked-legacy-account-', RAND(), NOW())), '2020-07-08 00:00:00');

INSERT IGNORE INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate)
VALUES ('Legacy Demo Account (backfilled placeholder -- original 2020 seed data, never a real registration, login permanently disabled)', NULL, 'Jai@gmail.com', MD5(CONCAT('locked-legacy-account-', RAND(), NOW())), '2020-07-08 00:00:00');

INSERT IGNORE INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate)
VALUES ('Legacy Demo Account (backfilled placeholder -- original 2020 seed data, never a real registration, login permanently disabled)', NULL, 'jone@gmail.com', MD5(CONCAT('locked-legacy-account-', RAND(), NOW())), '2020-07-08 00:00:00');

-- ── Step 2: the UNIQUE constraint tblusers.EmailId should have had all along ──

ALTER TABLE tblusers ADD UNIQUE KEY uq_tblusers_emailid (EmailId);

-- ── Step 3: normalize collation on the email columns that mismatch tblusers.EmailId ──

ALTER TABLE tblwishlist MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;
ALTER TABLE tbltraveldocuments MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;
ALTER TABLE tblreviews MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;
ALTER TABLE tblloyalty MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;
ALTER TABLE tblloyalty_log MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;
ALTER TABLE tblcart MODIFY UserEmail VARCHAR(100) COLLATE latin1_swedish_ci;

-- ── Step 4: foreign keys ──

ALTER TABLE tblbooking ADD CONSTRAINT fk_booking_package FOREIGN KEY (PackageId) REFERENCES tbltourpackages(PackageId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblbooking ADD CONSTRAINT fk_booking_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tblpayments ADD CONSTRAINT fk_payments_booking FOREIGN KEY (BookingId) REFERENCES tblbooking(BookingId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblpayment_events ADD CONSTRAINT fk_payment_events_payment FOREIGN KEY (PaymentId) REFERENCES tblpayments(PaymentId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblpayment_refunds ADD CONSTRAINT fk_payment_refunds_payment FOREIGN KEY (PaymentId) REFERENCES tblpayments(PaymentId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tblwishlist ADD CONSTRAINT fk_wishlist_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblwishlist ADD CONSTRAINT fk_wishlist_package FOREIGN KEY (PackageId) REFERENCES tbltourpackages(PackageId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tbltraveldocuments ADD CONSTRAINT fk_traveldocs_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tblreviews ADD CONSTRAINT fk_reviews_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblreviews ADD CONSTRAINT fk_reviews_package FOREIGN KEY (PackageId) REFERENCES tbltourpackages(PackageId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tblloyalty ADD CONSTRAINT fk_loyalty_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblloyalty_log ADD CONSTRAINT fk_loyalty_log_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblcart ADD CONSTRAINT fk_cart_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE tblenquiry ADD CONSTRAINT fk_enquiry_user FOREIGN KEY (EmailId) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE tblissues ADD CONSTRAINT fk_issues_user FOREIGN KEY (UserEmail) REFERENCES tblusers(EmailId) ON DELETE RESTRICT ON UPDATE CASCADE;

-- ── Step 5: CHECK constraints on core invariants ──

ALTER TABLE tblbooking ADD CONSTRAINT chk_booking_status CHECK (status IN (0,1,2));
ALTER TABLE tblusers ADD CONSTRAINT chk_users_notifications_enabled CHECK (NotificationsEnabled IN (0,1));
ALTER TABLE tblusers ADD CONSTRAINT chk_users_personalization_enabled CHECK (PersonalizationEnabled IN (0,1));
ALTER TABLE tbltourpackages ADD CONSTRAINT chk_package_price_nonneg CHECK (PackagePrice >= 0);
ALTER TABLE tblpayments ADD CONSTRAINT chk_payment_amount_nonneg CHECK (AmountMinor >= 0);
