-- Migration 015: convert remaining latin1 tables/columns to utf8mb4
-- (AUDIT_REPORT_ROUND15.md Section C / Phase 5 schema debt -- charset
-- inconsistency item).
--
-- REVISED after a real failure on first run: MySQL refuses to change the
-- charset of any column that is one side of a foreign key unless both
-- sides end up matching (error 1832, "Cannot change column ... used in a
-- foreign key constraint"). This database has 9 such FKs, all pointing at
-- tblusers.EmailId (added by migration 004, fk_check_constraints.sql):
-- tblbooking.UserEmail, tblcart.UserEmail, tblenquiry.EmailId,
-- tblissues.UserEmail, tblloyalty.UserEmail, tblloyalty_log.UserEmail,
-- tblreviews.UserEmail, tbltraveldocuments.UserEmail, tblwishlist.UserEmail
-- -- confirmed via a live information_schema query, not guessed. (The
-- other FKs in this database -- fk_booking_package, fk_reviews_package,
-- fk_wishlist_package, fk_payments_booking, fk_payment_events_payment,
-- fk_payment_refunds_payment -- all reference INT columns, which have no
-- charset and are entirely unaffected; they are left untouched.)
--
-- Fix: drop exactly those 9 FKs, run the same CONVERT TO CHARACTER SET
-- statements as before (now unblocked), then recreate the 9 FKs with
-- their original name and ON UPDATE/ON DELETE rules (also confirmed live
-- via information_schema, not assumed) so referential integrity (Rule 8,
-- migration 004's own purpose) is fully restored afterward. Nothing about
-- the data changes -- only the drop/recreate step is new; the safety
-- verification from the first version of this migration (utf8mb4 has
-- always been the app's connection charset; a full data scan found no
-- corruption risk) still applies unchanged. Note: the very first
-- statement below (`admin`) already ran successfully on the failed first
-- attempt, since DDL is not transactional in InnoDB -- re-running it here
-- is a harmless no-op, and this file is safe to run from the top.

-- Step 1: drop the 9 FKs whose columns are about to change charset.
ALTER TABLE `tblbooking`        DROP FOREIGN KEY `fk_booking_user`;
ALTER TABLE `tblcart`           DROP FOREIGN KEY `fk_cart_user`;
ALTER TABLE `tblenquiry`        DROP FOREIGN KEY `fk_enquiry_user`;
ALTER TABLE `tblissues`         DROP FOREIGN KEY `fk_issues_user`;
ALTER TABLE `tblloyalty`        DROP FOREIGN KEY `fk_loyalty_user`;
ALTER TABLE `tblloyalty_log`    DROP FOREIGN KEY `fk_loyalty_log_user`;
ALTER TABLE `tblreviews`        DROP FOREIGN KEY `fk_reviews_user`;
ALTER TABLE `tbltraveldocuments` DROP FOREIGN KEY `fk_traveldocs_user`;
ALTER TABLE `tblwishlist`       DROP FOREIGN KEY `fk_wishlist_user`;

-- Step 2: convert every table (unchanged from the first version of this
-- migration).
ALTER TABLE `admin`            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblbooking`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblcart`          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblenquiry`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblissues`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblloyalty`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblloyalty_log`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblmigrations`    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblpages`         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblpayments`      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblpayment_events` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblpayment_refunds` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblreviews`       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tbltourpackages`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tbltraveldocuments` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblusers`         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `tblwishlist`      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Step 3: recreate the 9 FKs, identical to how migration 004 defined them
-- (same constraint name, same ON UPDATE/ON DELETE rules) -- both sides are
-- now utf8mb4_general_ci, so this succeeds cleanly.
ALTER TABLE `tblbooking`        ADD CONSTRAINT `fk_booking_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblcart`           ADD CONSTRAINT `fk_cart_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblenquiry`        ADD CONSTRAINT `fk_enquiry_user` FOREIGN KEY (`EmailId`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblissues`         ADD CONSTRAINT `fk_issues_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblloyalty`        ADD CONSTRAINT `fk_loyalty_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblloyalty_log`    ADD CONSTRAINT `fk_loyalty_log_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblreviews`        ADD CONSTRAINT `fk_reviews_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tbltraveldocuments` ADD CONSTRAINT `fk_traveldocs_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE `tblwishlist`       ADD CONSTRAINT `fk_wishlist_user` FOREIGN KEY (`UserEmail`) REFERENCES `tblusers` (`EmailId`) ON UPDATE CASCADE ON DELETE RESTRICT;
