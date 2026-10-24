-- 020_performance_indexes.sql
-- Additive indexes only; nothing is dropped or rewritten. Each one backs a query that exists in the code today.
-- Re-running is safe: migrate.php treats "duplicate key name" (1061) as already present.
--
-- Part A: indexes the app has been creating at runtime from backend/config.php (CREATE INDEX IF NOT EXISTS).
-- Same names, so on installs that already have them this is a no-op, and installs that run with TMS_AUTO_SCHEMA=0
-- still get them from a reviewed migration.
ALTER TABLE tbltourpackages ADD INDEX idx_pkg_status (Status);
ALTER TABLE tbltourpackages ADD INDEX idx_pkg_status_price (Status, PackagePrice);
ALTER TABLE tblbooking ADD INDEX idx_booking_email (UserEmail);
ALTER TABLE tblbooking ADD INDEX idx_booking_status (status);
ALTER TABLE tblbooking ADD INDEX idx_booking_package (PackageId);
ALTER TABLE tblbooking ADD INDEX idx_booking_pkg_status_reg (PackageId, status, RegDate);
ALTER TABLE tblreviews ADD INDEX idx_reviews_pkg (PackageId);
ALTER TABLE tblreviews ADD INDEX idx_reviews_hotel (HotelId);
ALTER TABLE tblreviews ADD INDEX idx_reviews_activity (ActivityId);

-- Part B: new.
-- Customer dashboard / history: WHERE UserEmail = ? AND status = ? (frontend/dashboard.php counts per status).
ALTER TABLE tblbooking ADD INDEX idx_booking_user_status (UserEmail, status);
-- Package page: COUNT(*) FROM tblreviews WHERE PackageId = ? AND Status = 1; AVG(Rating) with the same filter (admin/manage-reviews.php, api/index.php).
ALTER TABLE tblreviews ADD INDEX idx_reviews_pkg_status (PackageId, Status);
-- Home page: latest approved reviews (WHERE Status = 1 ORDER BY PostingDate DESC LIMIT 3).
ALTER TABLE tblreviews ADD INDEX idx_reviews_status_posted (Status, PostingDate);
-- Home page: latest blog posts (ORDER BY CreatedAt DESC LIMIT 3).
ALTER TABLE tblblog ADD INDEX idx_blog_created (CreatedAt);
-- alerts.php: WHERE UserEmail = ? on both alert tables.
ALTER TABLE tbl_price_alerts ADD INDEX idx_price_alert_user (UserEmail);
ALTER TABLE tbl_availability_alerts ADD INDEX idx_avail_alert_user (UserEmail);
-- Package list "top rated" filter (Status = 1 AND AvgRating >= 4) and hotel/activity rating sort (WHERE Status = 1 ORDER BY AvgRating DESC).
ALTER TABLE tbltourpackages ADD INDEX idx_pkg_status_rating (Status, AvgRating);
ALTER TABLE tblhotels ADD INDEX idx_hotel_status_rating (Status, AvgRating);
ALTER TABLE tblactivities ADD INDEX idx_activity_status_rating (Status, AvgRating);
