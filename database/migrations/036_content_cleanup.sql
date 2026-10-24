-- 036: remove stale text inherited from the original template project (a different company with offices in Delhi).
-- Only rows that still contain that old text are touched. An empty detail makes page.php show its neutral built-in
-- About / Contact content, and the Contact page now hides address, phone and email until the operator sets
-- TMS_OFFICE_ADDRESS, TMS_SUPPORT_PHONE and TMS_SUPPORT_EMAIL in .env.
UPDATE tblpages SET detail = '' WHERE type = 'aboutus' AND (detail LIKE '%Shreya%' OR detail LIKE '%Delhi%');
UPDATE tblpages SET detail = '' WHERE type = 'contact' AND (detail LIKE '%Dwarka%' OR detail LIKE '%New Delhi%' OR detail LIKE '%Shreya%');
