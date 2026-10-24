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
 * Round 103 (master prompt item 24 -- "comments" was one of three
 * sub-features the spec lists with zero code anywhere before this round;
 * shared itinerary and voting are separate, larger follow-ups, not done
 * here). Callers must already have verified tms_group_is_member() before
 * calling this -- same convention as tms_add_group_expense() above.
 */
function tms_add_group_comment(PDO $dbh, int $groupId, string $email, string $comment): bool
{
    $comment = trim($comment);
    if ($comment === '' || mb_strlen($comment) > 1000) return false;
    try {
        $dbh->prepare(
            "INSERT INTO tblgroup_comments(GroupId, UserEmail, Comment) VALUES(:g, :e, :c)"
        )->execute([':g' => $groupId, ':e' => $email, ':c' => $comment]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS GroupTrip] add_comment failed: ' . $e->getMessage());
        return false;
    }
}

/** @return object[] rows with UserEmail, Comment, CreatedAt, oldest first */
function tms_get_group_comments(PDO $dbh, int $groupId): array
{
    $stmt = $dbh->prepare("SELECT UserEmail, Comment, CreatedAt FROM tblgroup_comments WHERE GroupId=:g ORDER BY CreatedAt ASC, id ASC");
    $stmt->execute([':g' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

/**
 * Round 104 (master prompt item 24 -- "voting", the last of the three
 * previously-missing sub-features; comments were added in Round 103).
 * Any member can start a poll -- same "not organizer-only" reasoning as
 * comments, since deciding what to vote on is itself a group decision.
 * $options is a plain array of option text strings; blank/duplicate-
 * after-trim entries are dropped, and at least 2 real options are
 * required (a 1-option or 0-option "poll" isn't a real vote).
 *
 * @param string[] $options
 * @return int the new poll id, or 0 on failure (e.g. fewer than 2 real options)
 */
function tms_create_group_poll(PDO $dbh, int $groupId, string $question, array $options, string $createdBy): int
{
    $question = trim($question);
    $cleanOptions = [];
    foreach ($options as $opt) {
        $opt = trim((string)$opt);
        if ($opt !== '' && !in_array($opt, $cleanOptions, true)) {
            $cleanOptions[] = $opt;
        }
    }
    if ($question === '' || count($cleanOptions) < 2) return 0;

    $dbh->beginTransaction();
    try {
        $dbh->prepare("INSERT INTO tblgroup_polls(GroupId, Question, CreatedBy) VALUES(:g, :q, :c)")
            ->execute([':g' => $groupId, ':q' => $question, ':c' => $createdBy]);
        $pollId = (int)$dbh->lastInsertId();

        $optIns = $dbh->prepare("INSERT INTO tblgroup_poll_options(PollId, OptionText) VALUES(:p, :o)");
        foreach ($cleanOptions as $opt) {
            $optIns->execute([':p' => $pollId, ':o' => $opt]);
        }
        $dbh->commit();
        return $pollId;
    } catch (Exception $e) {
        $dbh->rollBack();
        error_log('[TMS GroupTrip] create_poll failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Casts (or changes) one member's vote on a poll. $optionId is validated
 * to actually belong to $pollId, and $pollId to actually belong to
 * $groupId, so a member of one group can't vote on -- or even reach --
 * another group's poll by guessing an id (Rule 44). One vote per member
 * per poll, enforced at the database level (see the UNIQUE key on
 * tblgroup_poll_votes) -- resubmitting just moves that same vote to a
 * different option rather than casting a second one.
 */
function tms_cast_group_vote(PDO $dbh, int $groupId, int $pollId, int $optionId, string $email): bool
{
    $check = $dbh->prepare(
        "SELECT 1 FROM tblgroup_poll_options o
         JOIN tblgroup_polls p ON p.id = o.PollId
         WHERE o.id = :oid AND o.PollId = :pid AND p.GroupId = :g"
    );
    $check->execute([':oid' => $optionId, ':pid' => $pollId, ':g' => $groupId]);
    if (!$check->fetchColumn()) return false;

    try {
        $dbh->prepare(
            "INSERT INTO tblgroup_poll_votes(PollId, OptionId, UserEmail) VALUES(:p, :o, :e)
             ON DUPLICATE KEY UPDATE OptionId = VALUES(OptionId), CreatedAt = CURRENT_TIMESTAMP"
        )->execute([':p' => $pollId, ':o' => $optionId, ':e' => $email]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS GroupTrip] cast_vote failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array<int, array{
 *   id:int, question:string, created_by:string, created_at:string,
 *   total_votes:int, my_option_id:?int,
 *   options: array<int, array{id:int, text:string, votes:int, pct:float}>
 * }> polls for this group, newest first
 */
function tms_get_group_polls(PDO $dbh, int $groupId, string $email): array
{
    $polls = $dbh->prepare("SELECT id, Question, CreatedBy, CreatedAt FROM tblgroup_polls WHERE GroupId=:g ORDER BY CreatedAt DESC, id DESC");
    $polls->execute([':g' => $groupId]);
    $polls = $polls->fetchAll(PDO::FETCH_OBJ);
    if (!$polls) return [];

    $result = [];
    foreach ($polls as $poll) {
        $options = $dbh->prepare(
            "SELECT o.id, o.OptionText,
                    (SELECT COUNT(*) FROM tblgroup_poll_votes v WHERE v.OptionId = o.id) AS VoteCount
             FROM tblgroup_poll_options o WHERE o.PollId = :p ORDER BY o.id ASC"
        );
        $options->execute([':p' => $poll->id]);
        $options = $options->fetchAll(PDO::FETCH_OBJ);

        $totalVotes = array_sum(array_map(fn($o) => (int)$o->VoteCount, $options));

        $myVote = $dbh->prepare("SELECT OptionId FROM tblgroup_poll_votes WHERE PollId=:p AND UserEmail=:e");
        $myVote->execute([':p' => $poll->id, ':e' => $email]);
        $myOptionId = $myVote->fetchColumn();

        $result[] = [
            'id' => (int)$poll->id,
            'question' => $poll->Question,
            'created_by' => $poll->CreatedBy,
            'created_at' => $poll->CreatedAt,
            'total_votes' => $totalVotes,
            'my_option_id' => $myOptionId !== false ? (int)$myOptionId : null,
            'options' => array_map(function ($o) use ($totalVotes) {
                $votes = (int)$o->VoteCount;
                return [
                    'id' => (int)$o->id,
                    'text' => $o->OptionText,
                    'votes' => $votes,
                    'pct' => $totalVotes > 0 ? round(($votes / $totalVotes) * 100, 1) : 0.0,
                ];
            }, $options),
        ];
    }
    return $result;
}

/**
 * Adds one plan-of-record item to a group's shared itinerary. Any member
 * may add (same reasoning as comments and polls: planning what to do is a
 * group decision, not an organizer-only privilege). $dayLabel is free
 * text the group typed themselves -- e.g. "Day 1" or a date -- and is
 * optional (Round 105, master prompt item 24 -- "shared itinerary").
 */
function tms_add_itinerary_item(PDO $dbh, int $groupId, string $email, string $dayLabel, string $activity): bool
{
    $dayLabel = trim($dayLabel);
    $activity = trim($activity);
    if ($activity === '') return false;

    try {
        $dbh->prepare(
            "INSERT INTO tblgroup_itinerary_items(GroupId, DayLabel, Activity, AddedBy) VALUES(:g, :d, :a, :e)"
        )->execute([
            ':g' => $groupId,
            ':d' => $dayLabel !== '' ? $dayLabel : null,
            ':a' => $activity,
            ':e' => $email,
        ]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS GroupTrip] add_itinerary_item failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array<int, array{id:int, day_label:?string, activity:string, added_by:string, created_at:string}>
 *   in the order items were added (Round 105 keeps this deliberately flat
 *   and chronological -- no drag-to-reorder, no per-day grouping UI yet --
 *   matching the same "simple first" discipline Round 103 used for comments).
 */
function tms_get_group_itinerary(PDO $dbh, int $groupId): array
{
    $stmt = $dbh->prepare(
        "SELECT id, DayLabel, Activity, AddedBy, CreatedAt FROM tblgroup_itinerary_items WHERE GroupId=:g ORDER BY id ASC"
    );
    $stmt->execute([':g' => $groupId]);
    $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

    return array_map(function ($r) {
        return [
            'id' => (int)$r->id,
            'day_label' => $r->DayLabel,
            'activity' => $r->Activity,
            'added_by' => $r->AddedBy,
            'created_at' => $r->CreatedAt,
        ];
    }, $rows);
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
