<?php
/**
 * SupplierService — supplier / travel-partner marketplace logic.
 *
 * Design rules (see SUPPLIERS.md):
 *  - Tenant isolation: every supplier-facing read/write is scoped by SupplierId
 *    in the SQL itself (WHERE ... AND SupplierId = :sid). Object ids that come
 *    from the browser are never trusted on their own.
 *  - Suppliers can propose and describe packages, block dates, and accept or
 *    decline a booking. They can NOT publish packages, change booking status,
 *    touch payments or set their own commission. Those stay with admins and the
 *    existing BookingStateMachine / PaymentService.
 *  - Money: TotalCost is an integer BDT amount. Commission is a percentage held
 *    per supplier (set by an admin) and snapshotted into every settlement item.
 *  - A settlement can only contain a booking once (UNIQUE BookingId), and only
 *    confirmed + fully paid + completed bookings are eligible.
 */
final class SupplierService
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    private const SUPPLIER_TRANSITIONS = [
        'pending'   => ['verified', 'rejected'],
        'verified'  => ['suspended'],
        'suspended' => ['verified'],
        'rejected'  => ['pending'],
    ];
    private const SETTLEMENT_TRANSITIONS = [
        'draft'  => ['issued', 'void'],
        'issued' => ['paid', 'void'],
        'paid'   => [],
        'void'   => [],
    ];
    private const MAX_FAILED_LOGINS = 5;
    private const LOCK_MINUTES = 15;

    // ───────────────────────── schema ─────────────────────────

    public static function migrate(PDO $dbh): void
    {
        $t = [
"CREATE TABLE IF NOT EXISTS `tbl_suppliers` (
  `SupplierId` int(11) NOT NULL AUTO_INCREMENT,
  `BusinessName` varchar(160) NOT NULL,
  `ContactName` varchar(120) NOT NULL,
  `Email` varchar(190) NOT NULL,
  `Phone` varchar(30) NOT NULL,
  `BusinessRegNo` varchar(60) DEFAULT NULL,
  `Address` varchar(255) DEFAULT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'pending',
  `VerificationNotes` text DEFAULT NULL,
  `VerifiedAt` datetime DEFAULT NULL,
  `VerifiedBy` varchar(60) DEFAULT NULL,
  `CommissionPct` decimal(5,2) NOT NULL DEFAULT 10.00,
  `FailedLogins` int(11) NOT NULL DEFAULT 0,
  `LockedUntil` datetime DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`SupplierId`), UNIQUE KEY `uq_supplier_email` (`Email`), KEY `idx_supplier_status` (`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `tbl_supplier_blackouts` (
  `BlackoutId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) NOT NULL,
  `PackageId` int(11) NOT NULL,
  `DateFrom` date NOT NULL,
  `DateTo` date NOT NULL,
  `Reason` varchar(200) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BlackoutId`), KEY `idx_blackout_pkg` (`PackageId`, `DateFrom`, `DateTo`), KEY `idx_blackout_sup` (`SupplierId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `tbl_supplier_booking_responses` (
  `BookingId` int(11) NOT NULL,
  `SupplierId` int(11) NOT NULL,
  `Decision` varchar(12) NOT NULL,
  `Note` varchar(255) DEFAULT NULL,
  `DecidedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`BookingId`), KEY `idx_resp_supplier` (`SupplierId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `tbl_supplier_settlements` (
  `SettlementId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) NOT NULL,
  `InvoiceNo` varchar(40) DEFAULT NULL,
  `PeriodStart` date NOT NULL,
  `PeriodEnd` date NOT NULL,
  `CommissionPct` decimal(5,2) NOT NULL,
  `GrossAmount` decimal(12,2) NOT NULL DEFAULT 0,
  `CommissionAmount` decimal(12,2) NOT NULL DEFAULT 0,
  `NetAmount` decimal(12,2) NOT NULL DEFAULT 0,
  `Status` varchar(10) NOT NULL DEFAULT 'draft',
  `PaymentReference` varchar(120) DEFAULT NULL,
  `CreatedBy` varchar(60) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `IssuedAt` datetime DEFAULT NULL,
  `PaidAt` datetime DEFAULT NULL,
  PRIMARY KEY (`SettlementId`), UNIQUE KEY `uq_settlement_invoice` (`InvoiceNo`), KEY `idx_settlement_sup` (`SupplierId`, `Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `tbl_supplier_settlement_items` (
  `ItemId` int(11) NOT NULL AUTO_INCREMENT,
  `SettlementId` int(11) NOT NULL,
  `BookingId` int(11) NOT NULL,
  `GrossAmount` decimal(12,2) NOT NULL,
  `CommissionAmount` decimal(12,2) NOT NULL,
  `NetAmount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`ItemId`), UNIQUE KEY `uq_item_booking` (`BookingId`), KEY `idx_item_settlement` (`SettlementId`),
  CONSTRAINT `fk_item_settlement` FOREIGN KEY (`SettlementId`) REFERENCES `tbl_supplier_settlements` (`SettlementId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `tbl_supplier_audit` (
  `AuditId` int(11) NOT NULL AUTO_INCREMENT,
  `SupplierId` int(11) DEFAULT NULL,
  `Actor` varchar(80) NOT NULL,
  `Action` varchar(60) NOT NULL,
  `Detail` varchar(500) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AuditId`), KEY `idx_audit_sup` (`SupplierId`, `CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($t as $sql) {
            try { $dbh->exec($sql); } catch (Throwable $e) { error_log('[TourBuddy Suppliers] migration failed: ' . $e->getMessage()); }
        }
        if (function_exists('tms_ensure_columns')) {
            tms_ensure_columns($dbh, 'tbltourpackages', [
                'SupplierId'     => 'SupplierId INT DEFAULT NULL',
                'ApprovalStatus' => 'ApprovalStatus VARCHAR(20) DEFAULT NULL',
                'ReviewNote'     => 'ReviewNote VARCHAR(255) DEFAULT NULL',
            ]);
        }
        try { $dbh->exec('CREATE INDEX idx_pkg_supplier ON tbltourpackages(SupplierId)'); } catch (Throwable $e) { /* exists */ }
    }

    public static function audit(PDO $dbh, ?int $supplierId, string $actor, string $action, string $detail = ''): void
    {
        try {
            $dbh->prepare('INSERT INTO tbl_supplier_audit (SupplierId, Actor, Action, Detail) VALUES (?,?,?,?)')
                ->execute([$supplierId, mb_substr($actor, 0, 80), mb_substr($action, 0, 60), mb_substr($detail, 0, 500)]);
        } catch (Throwable $e) { error_log('[TourBuddy Suppliers] audit failed: ' . $e->getMessage()); }
    }

    // ───────────────────────── accounts ─────────────────────────

    /** @return array{ok:bool,errors:array<string,string>,id:?int} */
    public static function register(PDO $dbh, array $in): array
    {
        $e = [];
        $biz   = trim((string)($in['business_name'] ?? ''));
        $name  = trim((string)($in['contact_name'] ?? ''));
        $email = function_exists('tms_validate_email') ? tms_validate_email((string)($in['email'] ?? '')) : filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string)($in['phone'] ?? ''));
        $reg   = trim((string)($in['reg_no'] ?? ''));
        $addr  = trim((string)($in['address'] ?? ''));
        $pw    = (string)($in['password'] ?? '');
        if (mb_strlen($biz) < 2 || mb_strlen($biz) > 160) $e['business_name'] = 'Enter your business name (2-160 characters).';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $e['contact_name'] = 'Enter a contact person name.';
        if (!$email) $e['email'] = 'Enter a valid email address.';
        if (!preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) $e['phone'] = 'Enter a valid phone number.';
        if (mb_strlen($reg) > 60) $e['reg_no'] = 'Registration number is too long.';
        if (mb_strlen($addr) > 255) $e['address'] = 'Address is too long.';
        if (strlen($pw) < 10) $e['password'] = 'Use at least 10 characters.';
        elseif ($pw !== (string)($in['password2'] ?? '')) $e['password2'] = 'Passwords do not match.';
        if ($e) return ['ok' => false, 'errors' => $e, 'id' => null];

        try {
            $hash = function_exists('tms_hash_password') ? tms_hash_password($pw) : password_hash($pw, PASSWORD_DEFAULT);
            $dbh->prepare('INSERT INTO tbl_suppliers (BusinessName, ContactName, Email, Phone, BusinessRegNo, Address, PasswordHash) VALUES (?,?,?,?,?,?,?)')
                ->execute([$biz, $name, strtolower($email), $phone, $reg ?: null, $addr ?: null, $hash]);
            $id = (int)$dbh->lastInsertId();
            self::audit($dbh, $id, 'supplier:' . $id, 'register', $biz);
            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (PDOException $ex) {
            if ((string)$ex->getCode() === '23000') return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.'], 'id' => null];
            error_log('[TourBuddy Suppliers] register failed: ' . $ex->getMessage());
            return ['ok' => false, 'errors' => ['form' => 'Could not create the account. Please try again.'], 'id' => null];
        }
    }

    /** @return array{ok:bool,error:?string,supplier:?array} Generic messages: never reveals whether the email exists. */
    public static function login(PDO $dbh, string $email, string $password): array
    {
        $generic = 'Incorrect email or password.';
        $st = $dbh->prepare('SELECT * FROM tbl_suppliers WHERE Email = ? LIMIT 1');
        $st->execute([strtolower(trim($email))]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            static $dummy = null; $dummy ??= password_hash('timing-equaliser', PASSWORD_DEFAULT);
            password_verify($password, $dummy); // equalise timing for unknown emails
            return ['ok' => false, 'error' => $generic, 'supplier' => null];
        }
        if (!empty($s['LockedUntil']) && strtotime($s['LockedUntil']) > time()) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in a few minutes.', 'supplier' => null];
        }
        if (!password_verify($password, $s['PasswordHash'])) {
            $n = (int)$s['FailedLogins'] + 1;
            $lock = $n >= self::MAX_FAILED_LOGINS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            $dbh->prepare('UPDATE tbl_suppliers SET FailedLogins = ?, LockedUntil = ? WHERE SupplierId = ?')
                ->execute([$lock ? 0 : $n, $lock, $s['SupplierId']]);
            if ($lock) self::audit($dbh, (int)$s['SupplierId'], 'system', 'login_locked', 'too many failed attempts');
            return ['ok' => false, 'error' => $generic, 'supplier' => null];
        }
        if ($s['Status'] === self::STATUS_SUSPENDED || $s['Status'] === self::STATUS_REJECTED) {
            return ['ok' => false, 'error' => 'This account cannot sign in. Please contact support.', 'supplier' => null];
        }
        $dbh->prepare('UPDATE tbl_suppliers SET FailedLogins = 0, LockedUntil = NULL WHERE SupplierId = ?')->execute([$s['SupplierId']]);
        unset($s['PasswordHash']);
        return ['ok' => true, 'error' => null, 'supplier' => $s];
    }

    public static function find(PDO $dbh, int $sid): ?array
    {
        $st = $dbh->prepare('SELECT SupplierId, BusinessName, ContactName, Email, Phone, BusinessRegNo, Address, Status, VerificationNotes, VerifiedAt, CommissionPct, CreatedAt FROM tbl_suppliers WHERE SupplierId = ?');
        $st->execute([$sid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ───────────────────────── admin: supplier status ─────────────────────────

    public static function canTransitionSupplier(string $from, string $to): bool
    {
        return in_array($to, self::SUPPLIER_TRANSITIONS[$from] ?? [], true);
    }

    /** @return array{ok:bool,error:?string} */
    public static function setSupplierStatus(PDO $dbh, int $sid, string $to, string $actor, string $note = ''): array
    {
        $note = trim($note);
        if (in_array($to, ['rejected', 'suspended'], true) && $note === '') return ['ok' => false, 'error' => 'A reason is required.'];
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT Status FROM tbl_suppliers WHERE SupplierId = ? FOR UPDATE');
            $st->execute([$sid]);
            $from = $st->fetchColumn();
            if ($from === false) { $dbh->rollBack(); return ['ok' => false, 'error' => 'Supplier not found.']; }
            if (!self::canTransitionSupplier((string)$from, $to)) { $dbh->rollBack(); return ['ok' => false, 'error' => "Cannot change status from $from to $to."]; }
            $dbh->prepare('UPDATE tbl_suppliers SET Status = ?, VerificationNotes = ?, VerifiedAt = IF(? = "verified", NOW(), VerifiedAt), VerifiedBy = IF(? = "verified", ?, VerifiedBy) WHERE SupplierId = ?')
                ->execute([$to, $note !== '' ? $note : null, $to, $to, $actor, $sid]);
            if ($to === self::STATUS_SUSPENDED) {
                // Take every live package offline; admin must re-approve them after reinstatement.
                $dbh->prepare("UPDATE tbltourpackages SET Status = 0, ApprovalStatus = IF(ApprovalStatus = 'approved', 'pending_review', ApprovalStatus) WHERE SupplierId = ?")->execute([$sid]);
            }
            $dbh->commit();
            self::audit($dbh, $sid, $actor, 'status:' . $to, $note);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy Suppliers] setSupplierStatus: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the supplier.'];
        }
    }

    public static function setCommission(PDO $dbh, int $sid, float $pct, string $actor): bool
    {
        if ($pct < 0 || $pct > 50) return false;
        $n = $dbh->prepare('UPDATE tbl_suppliers SET CommissionPct = ? WHERE SupplierId = ?');
        $n->execute([number_format($pct, 2, '.', ''), $sid]);
        self::audit($dbh, $sid, $actor, 'commission', number_format($pct, 2) . '%');
        return true;
    }

    // ───────────────────────── packages ─────────────────────────

    /** @return array<int,array> only this supplier's packages */
    public static function packages(PDO $dbh, int $sid): array
    {
        $st = $dbh->prepare('SELECT PackageId, PackageName, PackageType, PackageLocation, PackagePrice, SeatsAvailable, Status, ApprovalStatus, ReviewNote, UpdationDate FROM tbltourpackages WHERE SupplierId = ? ORDER BY PackageId DESC');
        $st->execute([$sid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function package(PDO $dbh, int $sid, int $pid): ?array
    {
        $st = $dbh->prepare('SELECT * FROM tbltourpackages WHERE PackageId = ? AND SupplierId = ? LIMIT 1');
        $st->execute([$pid, $sid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create or update one of the supplier's own packages. Always lands in
     * pending_review and offline (Status 0): a supplier can never publish, and an
     * edit to an approved package takes it offline until an admin re-approves it.
     * @return array{ok:bool,errors:array<string,string>,id:?int}
     */
    public static function savePackage(PDO $dbh, int $sid, ?int $pid, array $in, ?string $newImage = null): array
    {
        $sup = self::find($dbh, $sid);
        if (!$sup || $sup['Status'] !== self::STATUS_VERIFIED) return ['ok' => false, 'errors' => ['form' => 'Your business must be verified before you can list packages.'], 'id' => null];
        $e = [];
        $f = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
        $name = $f('name', 200); $type = $f('type', 150); $loc = $f('location', 100); $country = $f('country', 100);
        $dur = $f('duration', 50); $features = $f('features', 255); $details = $f('details', 5000);
        $incl = $f('inclusions', 3000); $excl = $f('exclusions', 3000); $cancel = $f('cancellation', 3000);
        $price = filter_var($in['price'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000000]]);
        $seatsRaw = trim((string)($in['seats'] ?? ''));
        $seats = $seatsRaw === '' ? null : filter_var($seatsRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if (mb_strlen($name) < 3) $e['name'] = 'Enter a package name (at least 3 characters).';
        if ($type === '') $e['type'] = 'Enter a package type.';
        if ($loc === '') $e['location'] = 'Enter the location.';
        if ($price === false || $price === null) $e['price'] = 'Enter a whole-number price in BDT (per person).';
        if ($seatsRaw !== '' && $seats === false) $e['seats'] = 'Seats must be a whole number.';
        if ($details === '') $e['details'] = 'Describe the package.';
        if ($cancel === '') $e['cancellation'] = 'State your cancellation and refund terms; customers see this at checkout.';
        if ($e) return ['ok' => false, 'errors' => $e, 'id' => null];

        try {
            if ($pid) {
                $own = self::package($dbh, $sid, $pid);
                if (!$own) return ['ok' => false, 'errors' => ['form' => 'Package not found.'], 'id' => null]; // not yours = not found
                $sql = 'UPDATE tbltourpackages SET PackageName=?, PackageType=?, PackageLocation=?, Country=?, PackagePrice=?, PackageDuration=?, PackageFetures=?, PackageDetails=?, PackageDescription=?, Inclusions=?, Exclusions=?, CancellationPolicy=?, SeatsAvailable=?, Status=0, ApprovalStatus=\'pending_review\', ReviewNote=NULL'
                     . ($newImage !== null ? ', PackageImage=?' : '') . ' WHERE PackageId=? AND SupplierId=?';
                $args = [$name, $type, $loc, $country ?: null, $price, $dur ?: null, $features, $details, mb_substr($details, 0, 500), $incl ?: null, $excl ?: null, $cancel, $seats];
                if ($newImage !== null) $args[] = $newImage;
                array_push($args, $pid, $sid);
                $dbh->prepare($sql)->execute($args);
                self::audit($dbh, $sid, 'supplier:' . $sid, 'package_update', "#$pid");
                return ['ok' => true, 'errors' => [], 'id' => $pid];
            }
            $dbh->prepare('INSERT INTO tbltourpackages (PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageDescription, Inclusions, Exclusions, CancellationPolicy, SeatsAvailable, PackageImage, SupplierId, Status, ApprovalStatus) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,\'pending_review\')')
                ->execute([$name, $type, $loc, $country ?: null, $price, $dur ?: null, $features, $details, mb_substr($details, 0, 500), $incl ?: null, $excl ?: null, $cancel, $seats, $newImage ?? '', $sid]);
            $id = (int)$dbh->lastInsertId();
            self::audit($dbh, $sid, 'supplier:' . $sid, 'package_create', "#$id");
            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (Throwable $ex) {
            error_log('[TourBuddy Suppliers] savePackage: ' . $ex->getMessage());
            return ['ok' => false, 'errors' => ['form' => 'Could not save the package. Please try again.'], 'id' => null];
        }
    }

    /** @return array<int,array> packages waiting for an admin decision */
    public static function reviewQueue(PDO $dbh): array
    {
        return $dbh->query("SELECT p.PackageId, p.PackageName, p.PackageType, p.PackageLocation, p.PackagePrice, p.SeatsAvailable, p.CancellationPolicy, p.UpdationDate, s.SupplierId, s.BusinessName, s.Status AS SupplierStatus
                              FROM tbltourpackages p JOIN tbl_suppliers s ON s.SupplierId = p.SupplierId
                             WHERE p.ApprovalStatus = 'pending_review' ORDER BY p.PackageId")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok:bool,error:?string} */
    public static function reviewPackage(PDO $dbh, int $pid, string $decision, string $note, string $actor): array
    {
        $note = trim($note);
        if (!in_array($decision, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'Unknown decision.'];
        if ($decision === 'reject' && $note === '') return ['ok' => false, 'error' => 'Tell the supplier what to fix.'];
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare("SELECT p.SupplierId, p.ApprovalStatus, p.PackagePrice, s.Status AS SupStatus FROM tbltourpackages p LEFT JOIN tbl_suppliers s ON s.SupplierId = p.SupplierId WHERE p.PackageId = ? FOR UPDATE");
            $st->execute([$pid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !$r['SupplierId'] || $r['ApprovalStatus'] !== 'pending_review') { $dbh->rollBack(); return ['ok' => false, 'error' => 'This package is not waiting for review.']; }
            if ($decision === 'approve') {
                if ($r['SupStatus'] !== self::STATUS_VERIFIED) { $dbh->rollBack(); return ['ok' => false, 'error' => 'The supplier is not verified.']; }
                if ((int)$r['PackagePrice'] <= 0) { $dbh->rollBack(); return ['ok' => false, 'error' => 'The package has no valid price.']; }
                $dbh->prepare("UPDATE tbltourpackages SET Status = 1, ApprovalStatus = 'approved', ReviewNote = NULL WHERE PackageId = ?")->execute([$pid]);
            } else {
                $dbh->prepare("UPDATE tbltourpackages SET Status = 0, ApprovalStatus = 'rejected', ReviewNote = ? WHERE PackageId = ?")->execute([mb_substr($note, 0, 255), $pid]);
            }
            $dbh->commit();
            self::audit($dbh, (int)$r['SupplierId'], $actor, 'package_' . $decision, "#$pid " . $note);
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy Suppliers] reviewPackage: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the decision.'];
        }
    }

    // ───────────────────────── blackout dates ─────────────────────────

    /** @return array{ok:bool,error:?string} */
    public static function addBlackout(PDO $dbh, int $sid, int $pid, string $from, string $to, string $reason): array
    {
        $a = DateTime::createFromFormat('!Y-m-d', $from); $b = DateTime::createFromFormat('!Y-m-d', $to);
        if (!$a || !$b || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) return ['ok' => false, 'error' => 'Enter valid dates.'];
        if ($b < $a) return ['ok' => false, 'error' => 'The end date must not be before the start date.'];
        if ($b < new DateTime('today')) return ['ok' => false, 'error' => 'That period is already in the past.'];
        if (($a->diff($b)->days) > 730) return ['ok' => false, 'error' => 'A single blackout can cover at most two years.'];
        if (!self::package($dbh, $sid, $pid)) return ['ok' => false, 'error' => 'Package not found.'];
        $dbh->prepare('INSERT INTO tbl_supplier_blackouts (SupplierId, PackageId, DateFrom, DateTo, Reason) VALUES (?,?,?,?,?)')
            ->execute([$sid, $pid, $from, $to, mb_substr(trim($reason), 0, 200) ?: null]);
        self::audit($dbh, $sid, 'supplier:' . $sid, 'blackout_add', "#$pid $from..$to");
        return ['ok' => true, 'error' => null];
    }

    public static function deleteBlackout(PDO $dbh, int $sid, int $blackoutId): bool
    {
        $st = $dbh->prepare('DELETE FROM tbl_supplier_blackouts WHERE BlackoutId = ? AND SupplierId = ?');
        $st->execute([$blackoutId, $sid]);
        if ($st->rowCount() === 1) { self::audit($dbh, $sid, 'supplier:' . $sid, 'blackout_delete', "#$blackoutId"); return true; }
        return false;
    }

    /** @return array<int,array> */
    public static function blackouts(PDO $dbh, int $sid): array
    {
        $st = $dbh->prepare('SELECT b.BlackoutId, b.PackageId, p.PackageName, b.DateFrom, b.DateTo, b.Reason FROM tbl_supplier_blackouts b JOIN tbltourpackages p ON p.PackageId = b.PackageId WHERE b.SupplierId = ? AND p.SupplierId = ? AND b.DateTo >= CURDATE() ORDER BY b.DateFrom');
        $st->execute([$sid, $sid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Customer-side rule: does the requested stay overlap a blackout for this package?
     * Returns a customer-safe message, or null when the dates are fine (or the table does not exist yet).
     */
    public static function blackoutConflict(PDO $dbh, int $packageId, string $fromYmd, string $toYmd): ?string
    {
        try {
            $st = $dbh->prepare('SELECT 1 FROM tbl_supplier_blackouts WHERE PackageId = ? AND DateFrom <= ? AND DateTo >= ? LIMIT 1');
            $st->execute([$packageId, $toYmd, $fromYmd]);
            if ($st->fetchColumn()) return 'This package is not available for some of the dates you selected. Please choose different dates.';
        } catch (Throwable $e) {
            error_log('[TourBuddy Suppliers] blackout check failed: ' . $e->getMessage());
        }
        return null;
    }

    // ───────────────────────── bookings ─────────────────────────

    /**
     * Bookings for this supplier's packages only. The customer's name and mobile
     * number are shown only once the booking is confirmed; the email is never shown.
     * @return array<int,array>
     */
    public static function bookings(PDO $dbh, int $sid, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $st = $dbh->prepare("SELECT b.BookingId, b.PackageId, p.PackageName, b.FromDate, b.ToDate, b.NoOfPersons, b.TotalCost, b.status, b.PaymentStatus,
                    IF(b.status = 1, u.FullName, NULL) AS CustomerName, IF(b.status = 1, b.MobileNumber, NULL) AS CustomerMobile,
                    r.Decision, r.Note AS ResponseNote
               FROM tblbooking b
               JOIN tbltourpackages p ON p.PackageId = b.PackageId AND p.SupplierId = :sid
               LEFT JOIN tblusers u ON u.EmailId = b.UserEmail
               LEFT JOIN tbl_supplier_booking_responses r ON r.BookingId = b.BookingId
              ORDER BY b.BookingId DESC LIMIT $limit");
        $st->execute([':sid' => $sid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok:bool,error:?string} Records the supplier's answer only; booking status is the admin's. */
    public static function respondToBooking(PDO $dbh, int $sid, int $bookingId, string $decision, string $note): array
    {
        if (!in_array($decision, ['accepted', 'declined'], true)) return ['ok' => false, 'error' => 'Unknown response.'];
        $note = mb_substr(trim($note), 0, 255);
        if ($decision === 'declined' && $note === '') return ['ok' => false, 'error' => 'Please give a reason when declining.'];
        $st = $dbh->prepare('SELECT b.status FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId WHERE b.BookingId = ? AND p.SupplierId = ?');
        $st->execute([$bookingId, $sid]);
        $status = $st->fetchColumn();
        if ($status === false) return ['ok' => false, 'error' => 'Booking not found.'];
        if ((int)$status === 2) return ['ok' => false, 'error' => 'This booking is already cancelled.'];
        $dbh->prepare('INSERT INTO tbl_supplier_booking_responses (BookingId, SupplierId, Decision, Note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE Decision = VALUES(Decision), Note = VALUES(Note)')
            ->execute([$bookingId, $sid, $decision, $note ?: null]);
        self::audit($dbh, $sid, 'supplier:' . $sid, 'booking_' . $decision, "#$bookingId " . $note);
        return ['ok' => true, 'error' => null];
    }

    /** Admin view: declined bookings that are still live and need a decision. */
    public static function declinedNeedingAttention(PDO $dbh): array
    {
        return $dbh->query("SELECT b.BookingId, p.PackageName, s.BusinessName, r.Note, b.status, b.PaymentStatus
                              FROM tbl_supplier_booking_responses r JOIN tblbooking b ON b.BookingId = r.BookingId
                              JOIN tbltourpackages p ON p.PackageId = b.PackageId JOIN tbl_suppliers s ON s.SupplierId = r.SupplierId
                             WHERE r.Decision = 'declined' AND b.status IN (0,1) ORDER BY b.BookingId DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ───────────────────────── settlements ─────────────────────────

    private static function eligibleSql(bool $lock): string
    {
        return "SELECT b.BookingId, b.TotalCost FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
                 WHERE p.SupplierId = :sid AND b.status = 1 AND b.PaymentStatus = 'paid'
                   AND b.ToDate >= :a AND b.ToDate <= :b AND b.ToDate < CURDATE()
                   AND NOT EXISTS (SELECT 1 FROM tbl_supplier_settlement_items i WHERE i.BookingId = b.BookingId)
                 ORDER BY b.BookingId" . ($lock ? ' FOR UPDATE' : '');
    }

    /** @return array{eligible:array,gross:int,review:int} bookings that would be included, plus partially-refunded ones needing manual review */
    public static function previewSettlement(PDO $dbh, int $sid, string $start, string $end): array
    {
        $st = $dbh->prepare(self::eligibleSql(false));
        $st->execute([':sid' => $sid, ':a' => $start, ':b' => $end]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $gross = 0; foreach ($rows as $r) $gross += (int)$r['TotalCost'];
        $rv = $dbh->prepare("SELECT COUNT(*) FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId WHERE p.SupplierId = ? AND b.status = 1 AND b.PaymentStatus = 'partially_refunded' AND b.ToDate >= ? AND b.ToDate <= ?");
        $rv->execute([$sid, $start, $end]);
        return ['eligible' => $rows, 'gross' => $gross, 'review' => (int)$rv->fetchColumn()];
    }

    /** @return array{ok:bool,error:?string,id:?int} */
    public static function createSettlement(PDO $dbh, int $sid, string $start, string $end, string $actor): array
    {
        $a = DateTime::createFromFormat('!Y-m-d', $start); $b = DateTime::createFromFormat('!Y-m-d', $end);
        if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end || $b < $a) return ['ok' => false, 'error' => 'Enter a valid period.', 'id' => null];
        try {
            $dbh->beginTransaction();
            $sp = $dbh->prepare('SELECT CommissionPct, Status FROM tbl_suppliers WHERE SupplierId = ? FOR UPDATE');
            $sp->execute([$sid]);
            $sup = $sp->fetch(PDO::FETCH_ASSOC);
            if (!$sup) { $dbh->rollBack(); return ['ok' => false, 'error' => 'Supplier not found.', 'id' => null]; }
            $pct = (float)$sup['CommissionPct'];
            $st = $dbh->prepare(self::eligibleSql(true));
            $st->execute([':sid' => $sid, ':a' => $start, ':b' => $end]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { $dbh->rollBack(); return ['ok' => false, 'error' => 'No eligible bookings in this period (confirmed, fully paid, trip completed, not already settled).', 'id' => null]; }
            $dbh->prepare('INSERT INTO tbl_supplier_settlements (SupplierId, PeriodStart, PeriodEnd, CommissionPct, CreatedBy) VALUES (?,?,?,?,?)')
                ->execute([$sid, $start, $end, number_format($pct, 2, '.', ''), $actor]);
            $setId = (int)$dbh->lastInsertId();
            $ins = $dbh->prepare('INSERT INTO tbl_supplier_settlement_items (SettlementId, BookingId, GrossAmount, CommissionAmount, NetAmount) VALUES (?,?,?,?,?)');
            $g = 0.0; $c = 0.0; $n = 0.0;
            foreach ($rows as $r) {
                $gross = (float)(int)$r['TotalCost'];
                $comm = round($gross * $pct / 100, 2);
                $net = round($gross - $comm, 2);
                $ins->execute([$setId, $r['BookingId'], $gross, $comm, $net]);
                $g += $gross; $c += $comm; $n += $net;
            }
            $inv = 'SET-' . date('Ym') . '-' . str_pad((string)$setId, 5, '0', STR_PAD_LEFT);
            $dbh->prepare('UPDATE tbl_supplier_settlements SET GrossAmount = ?, CommissionAmount = ?, NetAmount = ?, InvoiceNo = ? WHERE SettlementId = ?')
                ->execute([round($g, 2), round($c, 2), round($n, 2), $inv, $setId]);
            $dbh->commit();
            self::audit($dbh, $sid, $actor, 'settlement_create', "$inv " . count($rows) . ' bookings');
            return ['ok' => true, 'error' => null, 'id' => $setId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy Suppliers] createSettlement: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not create the settlement (a booking may already be in another settlement).', 'id' => null];
        }
    }

    public static function canTransitionSettlement(string $from, string $to): bool
    {
        return in_array($to, self::SETTLEMENT_TRANSITIONS[$from] ?? [], true);
    }

    /** @return array{ok:bool,error:?string} */
    public static function transitionSettlement(PDO $dbh, int $settlementId, string $to, string $actor, string $reference = ''): array
    {
        $reference = trim($reference);
        if ($to === 'paid' && $reference === '') return ['ok' => false, 'error' => 'Enter the payment reference (bank/bKash transaction ID).'];
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT SupplierId, Status, InvoiceNo FROM tbl_supplier_settlements WHERE SettlementId = ? FOR UPDATE');
            $st->execute([$settlementId]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) { $dbh->rollBack(); return ['ok' => false, 'error' => 'Settlement not found.']; }
            if (!self::canTransitionSettlement((string)$s['Status'], $to)) { $dbh->rollBack(); return ['ok' => false, 'error' => "Cannot change a {$s['Status']} settlement to $to."]; }
            if ($to === 'issued') $dbh->prepare("UPDATE tbl_supplier_settlements SET Status='issued', IssuedAt=NOW() WHERE SettlementId = ?")->execute([$settlementId]);
            elseif ($to === 'paid') $dbh->prepare("UPDATE tbl_supplier_settlements SET Status='paid', PaidAt=NOW(), PaymentReference=? WHERE SettlementId = ?")->execute([mb_substr($reference, 0, 120), $settlementId]);
            else { // void: release the bookings so they can be settled again
                $dbh->prepare('DELETE FROM tbl_supplier_settlement_items WHERE SettlementId = ?')->execute([$settlementId]);
                $dbh->prepare("UPDATE tbl_supplier_settlements SET Status='void' WHERE SettlementId = ?")->execute([$settlementId]);
            }
            $dbh->commit();
            self::audit($dbh, (int)$s['SupplierId'], $actor, 'settlement_' . $to, (string)$s['InvoiceNo'] . ($reference !== '' ? " ref $reference" : ''));
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy Suppliers] transitionSettlement: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the settlement.'];
        }
    }

    /** @return array<int,array> */
    public static function settlements(PDO $dbh, ?int $sid = null, ?array $statuses = null): array
    {
        $sql = 'SELECT st.*, s.BusinessName FROM tbl_supplier_settlements st JOIN tbl_suppliers s ON s.SupplierId = st.SupplierId WHERE 1=1';
        $args = [];
        if ($sid !== null) { $sql .= ' AND st.SupplierId = ?'; $args[] = $sid; }
        if ($statuses) { $sql .= ' AND st.Status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; $args = array_merge($args, $statuses); }
        $st = $dbh->prepare($sql . ' ORDER BY st.SettlementId DESC LIMIT 200');
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
