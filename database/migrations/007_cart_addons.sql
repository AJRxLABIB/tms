-- Migration: 007_cart_addons
-- Round 91 (master prompt items 10/81 -- Booking Engine / Unified Platform).
-- Round 87 (migration 006) added a per-package add-on catalog and wired
-- selection into the direct "Book Now" path only, deliberately leaving
-- Add to Cart / cart.php / checkout.php out of scope. This closes that gap:
-- add-ons selected via "Add to Cart" now carry through the cart and into the
-- real booking created at checkout, the same as they already did for
-- "Book Now".
--
-- This file is the historical-record companion to the same, already-applied
-- change made live via CREATE TABLE IF NOT EXISTS in backend/config.php and
-- includes/config.php -- safe to run again on an install that already has
-- this table (database/migrate.php tolerates "table already exists" errors).

-- Unlike tblbookingaddons, this is NOT a snapshot table: a cart is transient
-- scratch state (hours/days, never a permanent receipt), so Name/Price are
-- read live from tblpackageaddons whenever the cart or checkout page
-- renders. Only the real booking created at checkout gets a snapshotted
-- tblbookingaddons row (unchanged from Round 87). No FK CASCADE (this
-- project's convention throughout, same as tblbookingaddons) -- cart.php's
-- single-item removal and checkout.php's post-checkout cart clear both
-- explicitly delete the matching rows here themselves.
CREATE TABLE IF NOT EXISTS `tblcartaddons` (
    `Id` INT(11) NOT NULL AUTO_INCREMENT,
    `CartId` INT(11) NOT NULL,
    `AddOnId` INT(11) NOT NULL,
    `Quantity` INT(11) NOT NULL DEFAULT 1,
    `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`Id`),
    INDEX `idx_cartaddons_cart` (`CartId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
