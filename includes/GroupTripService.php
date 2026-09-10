<?php
/**
 * GroupTripService.php — group travel + expense splitting (Rule 24/25).
 *
 * Every money value in here is an integer (minor units, e.g. poisha for
 * BDT) from creation to settlement -- no floats anywhere in this file, so
 * there is no rounding-drift class of bug to worry about (Rule 25: never
 * use floating point for financial calculations).
 */

function tms_group_is_member(PDO $dbh, int $groupId, string $email): bool
{
    $stmt = $dbh->prepare("SELECT 1 FROM tblgroup_members WHERE GroupId=:g AND UserEmail=:e");
    $stmt->execute([':g' => $groupId, ':e' => $email]);
    return (bool)$stmt->fetchColumn();
}

function tms_create_group_trip(PDO $dbh, string $name, string $creatorEmail, ?int $packageId = null): int
{
    $dbh->beginTransaction();
    try {
        $dbh->prepare("INSERT INTO tblgroup_trips(Name, PackageId, CreatedBy) VALUES(:n, :p, :c)")
            ->execute([':n' => $name, ':p' => $packageId, ':c' => $creatorEmail]);
        $groupId = (int)$dbh->lastInsertId();
        $dbh->prepare("INSERT INTO tblgroup_members(GroupId, UserEmail, Role) VALUES(:g, :e, 'organizer')")
            ->execute([':g' => $groupId, ':e' => $creatorEmail]);
        $dbh->commit();
        return $groupId;
    } catch (Exception $e) {
        $dbh->rollBack();
        error_log('[TMS GroupTrip] create failed: ' . $e->getMessage());
        return 0;
    }
}

/** @return array{ok:bool, message:string} */
function tms_add_group_member(PDO $dbh, int $groupId, string $email): array
{
    $userCheck = $dbh->prepare("SELECT 1 FROM tblusers WHERE EmailId = :e");
    $userCheck->execute([':e' => $email]);
    if (!$userCheck->fetchColumn()) {
        return ['ok' => false, 'message' => "No TMS account found for {$email} -- they need to register first."];
    }
    try {
        $dbh->prepare("INSERT INTO tblgroup_members(GroupId, UserEmail, Role) VALUES(:g, :e, 'member')")
            ->execute([':g' => $groupId, ':e' => $email]);
        tms_notify($dbh, $email, "You've been added to a group trip.", 'group-trip.php?id=' . $groupId);
        return ['ok' => true, 'message' => "{$email} added to the group."];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => "Could not add {$email} — they may already be in this group."];
    }
}

function tms_add_group_expense(PDO $dbh, int $groupId, string $paidByEmail, string $description, int $amountMinor): bool
{
    if ($amountMinor <= 0) return false;
    try {
        $dbh->prepare(
            "INSERT INTO tblgroup_expenses(GroupId, PaidByEmail, Description, AmountMinor) VALUES(:g, :p, :d, :a)"
        )->execute([':g' => $groupId, ':p' => $paidByEmail, ':d' => $description, ':a' => $amountMinor]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS GroupTrip] add_expense failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Even split across all members, integer-exact (any leftover minor units
 * from the division go to the first N members in email order, so the sum
 * of shares always equals the total to the last unit -- never "lost" to
 * rounding).
 *
 * @return array{
 *   total_minor:int, members:string[], paid:array<string,int>,
 *   fair_share:array<string,int>, balance:array<string,int>,
 *   settlements:array<array{from:string,to:string,amount_minor:int}>
 * }
 */
function tms_compute_group_settlement(PDO $dbh, int $groupId): array
{
    $members = $dbh->prepare("SELECT UserEmail FROM tblgroup_members WHERE GroupId=:g ORDER BY UserEmail");
    $members->execute([':g' => $groupId]);
    $members = $members->fetchAll(PDO::FETCH_COLUMN);

    $expenses = $dbh->prepare("SELECT PaidByEmail, AmountMinor FROM tblgroup_expenses WHERE GroupId=:g");
    $expenses->execute([':g' => $groupId]);
    $expenses = $expenses->fetchAll(PDO::FETCH_OBJ);

    $paid = array_fill_keys($members, 0);
    $totalMinor = 0;
    foreach ($expenses as $e) {
        $paid[$e->PaidByEmail] = ($paid[$e->PaidByEmail] ?? 0) + (int)$e->AmountMinor;
        $totalMinor += (int)$e->AmountMinor;
    }

    $n = max(1, count($members));
    $baseShare = intdiv($totalMinor, $n);
    $remainder = $totalMinor % $n; // distribute 1 extra minor unit each to the first $remainder members

    $fairShare = [];
    $balance = [];
    foreach (array_values($members) as $i => $email) {
        $fairShare[$email] = $baseShare + ($i < $remainder ? 1 : 0);
        $balance[$email] = $paid[$email] - $fairShare[$email]; // positive = owed money, negative = owes money
    }

    // Greedy minimum-cash-flow settlement: repeatedly match the biggest
    // creditor with the biggest debtor until everyone nets to zero.
    $creditors = [];
    $debtors = [];
    foreach ($balance as $email => $amount) {
        if ($amount > 0) $creditors[$email] = $amount;
        elseif ($amount < 0) $debtors[$email] = -$amount;
    }

    $settlements = [];
    while (!empty($creditors) && !empty($debtors)) {
        arsort($creditors);
        arsort($debtors);
        $creditorEmail = array_key_first($creditors);
        $debtorEmail = array_key_first($debtors);
        $amount = min($creditors[$creditorEmail], $debtors[$debtorEmail]);

        if ($amount > 0) {
            $settlements[] = ['from' => $debtorEmail, 'to' => $creditorEmail, 'amount_minor' => $amount];
        }

        $creditors[$creditorEmail] -= $amount;
        $debtors[$debtorEmail] -= $amount;
        if ($creditors[$creditorEmail] <= 0) unset($creditors[$creditorEmail]);
        if ($debtors[$debtorEmail] <= 0) unset($debtors[$debtorEmail]);
    }

    return [
        'total_minor' => $totalMinor,
        'members' => $members,
        'paid' => $paid,
        'fair_share' => $fairShare,
        'balance' => $balance,
        'settlements' => $settlements,
    ];
}
