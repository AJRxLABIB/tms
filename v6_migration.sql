-- ============================================================
-- TMS v6 Full Feature Migration
-- Run ONCE after importing tms.sql (base database)
-- ============================================================

-- 1. Wishlist
CREATE TABLE IF NOT EXISTS `tblwishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) DEFAULT NULL,
  `PackageId` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_wish` (`UserEmail`,`PackageId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 2. Loyalty
CREATE TABLE IF NOT EXISTS `tblloyalty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) DEFAULT NULL,
  `Points` int(11) DEFAULT 0,
  `TotalEarned` int(11) DEFAULT 0,
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_email` (`UserEmail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tblloyalty_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) DEFAULT NULL,
  `Points` int(11) DEFAULT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 3. Coupons
CREATE TABLE IF NOT EXISTS `tblcoupons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Code` varchar(50) DEFAULT NULL,
  `DiscountPercent` int(11) DEFAULT 10,
  `UserEmail` varchar(100) DEFAULT NULL,
  `ExpiryDate` date DEFAULT NULL,
  `IsUsed` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`Code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 4. Add coupon fields to tblbooking
ALTER TABLE `tblbooking` ADD COLUMN IF NOT EXISTS `CouponCode` varchar(50) DEFAULT NULL;
ALTER TABLE `tblbooking` ADD COLUMN IF NOT EXISTS `DiscountApplied` int(11) DEFAULT 0;
ALTER TABLE `tblbooking` ADD COLUMN IF NOT EXISTS `CancelledBy` varchar(10) DEFAULT NULL;

-- 5. Blog
CREATE TABLE IF NOT EXISTS `tblblog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Title` varchar(300) DEFAULT NULL,
  `Content` mediumtext DEFAULT NULL,
  `Tag` varchar(100) DEFAULT NULL,
  `CoverImage` varchar(200) DEFAULT NULL,
  `AuthorId` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 6. Activity Log
CREATE TABLE IF NOT EXISTS `tblactivity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Actor` varchar(150) DEFAULT NULL,
  `Action` varchar(150) DEFAULT NULL,
  `Details` text DEFAULT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 7. Sample blog posts
INSERT IGNORE INTO `tblblog` (`Title`,`Content`,`Tag`,`AuthorId`) VALUES
('Top 10 Tips for First-Time Travelers','Planning your first international trip can feel overwhelming. Here are the top 10 tips to make it smooth and memorable.\n\n1. Book flights early for the best prices.\n2. Always carry a digital and paper copy of your passport.\n3. Get travel insurance before you go.\n4. Pack light — you can always buy things there.\n5. Learn a few basic phrases in the local language.\n6. Download offline maps before you leave.\n7. Keep emergency contacts saved on your phone.\n8. Carry some local currency for small vendors.\n9. Notify your bank before travelling internationally.\n10. Stay flexible — some of the best moments are unplanned!','Travel Tips',1),
('Hidden Gems of Southeast Asia','Southeast Asia is famous for its beaches and temples, but beyond the tourist trail lie incredible hidden destinations.\n\nConsider visiting Kampot in Cambodia for a laid-back riverside experience. Or explore the floating villages of Halong Bay at dawn before the tour boats arrive. In Thailand, skip Phuket and head to Koh Lanta for a quieter beach vibe.\n\nRemember: the best discoveries happen when you travel slowly and talk to locals.','Destinations',1),
('How to Travel on a Budget Without Sacrificing Comfort','Travelling cheaply does not mean travelling miserably. Here are smart strategies:\n\n• Use budget airlines but watch for hidden fees.\n• Stay in boutique guesthouses instead of big hotels.\n• Eat where locals eat — street food is often the best food.\n• Travel shoulder season (just before or after peak) for big savings.\n• Use free walking tours in every city.\n• Book accommodation with free breakfast included.\n\nWith smart planning, you can travel comfortably for far less than you imagine.','Budget Travel',1);

-- 8. Sample coupons
INSERT IGNORE INTO `tblcoupons` (`Code`,`DiscountPercent`,`UserEmail`,`ExpiryDate`,`IsUsed`) VALUES
('WELCOME10', 10, NULL, DATE_ADD(CURDATE(), INTERVAL 90 DAY), 0),
('SUMMER20', 20, NULL, DATE_ADD(CURDATE(), INTERVAL 60 DAY), 0),
('TMS2026', 15, NULL, DATE_ADD(CURDATE(), INTERVAL 120 DAY), 0);

-- Done
SELECT 'TMS v6 migration completed successfully.' as status;
