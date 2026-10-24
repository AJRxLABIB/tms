<?php
// ONE-TIME cleanup for manual E2E-script verification performed via the
// live browser this round -- deletes every throwaway
// e2e-verify*@tms-testing.local account and every row in the 9 real
// tables with a FOREIGN KEY on tblusers.EmailId (confirmed live via
// information_schema during the migration-015 work earlier this session:
// tblbooking, tblcart, tblenquiry, tblissues, tblloyalty, tblloyalty_log,
// tblreviews, tbltraveldocuments, tblwishlist -- all ON DELETE RESTRICT,
// so tblusers itself can't be deleted until all nine are clear).
// DISABLE (404 stub) IMMEDIATELY AFTER USE.
http_response_code(404); die('Not found.');
