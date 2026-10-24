<?php
/**
 * ActionProposals — the ONLY way a chat message can lead towards a consequential action (cancel / change a booking, use a coupon),
 * and it never performs the action.
 *
 * Flow:
 *   1. detect()   pure function: reads the visitor's own words with fixed patterns (never the model) and returns an intent or null.
 *   2. propose()  signed-in visitors only. Verifies in the database that the booking really belongs to the session identity and is in a
 *                 state where the action makes sense, then stores a PROPOSAL row (idempotent per user, action, parameters and day).
 *                 Nothing about the booking changes.
 *   3. confirm()  a separate, CSRF-protected request from the visitor's own click (api/ai.php?action=action_confirm). It re-checks
 *                 ownership and state under a row lock, marks the proposal confirmed and hands the visitor to the existing page that
 *                 performs the action with its own checks (tour-history.php, checkout.php). If a real executor is ever registered with
 *                 registerExecutor() it is called instead; none exists today, because no reusable business function exists for these
 *                 actions (the cancellation logic lives inline in tour-history.php).
 *
 * The language model cannot create, confirm or execute a proposal: it is not in ToolRegistry, and the model's output is never parsed
 * for actions. Proposals expire after 30 minutes.
 */
final class ActionProposals
{
    public const TTL_MINUTES = 30;
    public const MAX_OPEN_PER_HOUR = 5;
    public const TYPES = ['cancel_booking', 'modify_booking', 'apply_coupon'];
    /** Page that really performs each action (same-site, relative). */
    public const HANDOFF = ['cancel_booking' => 'tour-history.php', 'modify_booking' => 'tour-history.php', 'apply_coupon' => 'checkout.php'];

    /** @var array<string,callable> type => fn(PDO,array $proposalRow): array{ok:bool,message:string} */
    private static array $executors = [];
    public static function registerExecutor(string $type, callable $fn): void { if (in_array($type, self::TYPES, true)) self::$executors[$type] = $fn; }
    public static function clearExecutors(): void { self::$executors = []; }

    /** @return ?array{type:string,params:array} */
    public static function detect(string $message): ?array
    {
        $m = mb_strtolower($message, 'UTF-8');
        require_once __DIR__ . '/BanglaNormalizer.php';
        $d = BanglaNormalizer::digits($m);
        $bk = null;
        if (preg_match('/\b(?:booking|reservation|bk)\s*(?:id|no\.?|number)?\s*[:#-]?\s*(?:bk)?\s*(\d{1,9})\b|বুকিং\s*(?:নম্বর|আইডি|নং)?\s*[:#-]?\s*(\d{1,9})/u', $d, $x)) {
            $n = (int)($x[1] !== '' ? $x[1] : ($x[2] ?? 0)); if ($n > 0) $bk = $n;
        }
        // Questions about the policy are not requests ("what is the cancellation policy", "can I cancel?" without a booking number).
        if ($bk !== null && preg_match('/\b(cancel|call off|withdraw)\b|বাতিল/u', $d) && !preg_match('/\b(polic(?:y|ies)|rules?|terms|how (?:does|do)|what happens|charges?|fee|fees)\b|নীতি|নিয়ম/u', $d))
            return ['type' => 'cancel_booking', 'params' => ['booking_id' => $bk]];
        if ($bk !== null && preg_match('/\b(reschedul\w*|postpone|change (?:the |my )?(?:date|dates)|modify|move (?:the |my )?(?:trip|booking))\b|তারিখ\s*(?:বদল|পরিবর্তন)/u', $d))
            return ['type' => 'modify_booking', 'params' => ['booking_id' => $bk]];
        if (preg_match('/\b(?:apply|use|redeem)\b.{0,20}\bcoupon\b\s*(?:code)?\s*[:#]?\s*([a-z0-9]{3,20})\b/iu', $d, $c) && !in_array($c[1], ['code', 'on', 'to', 'for', 'my', 'the', 'a'], true))
            return ['type' => 'apply_coupon', 'params' => ['code' => strtoupper($c[1])]];
        return null;
    }

    /** One-line, plain description shown on the confirmation card (built by code from verified data, not by the model). */
    public static function summary(string $type, array $params, ?array $booking = null): string
    {
        $pk = $booking['PackageName'] ?? null; $ref = '#' . (int)($params['booking_id'] ?? 0);
        return match ($type) {
            'cancel_booking' => 'Cancel booking ' . $ref . ($pk ? ' (' . $pk . ')' : '') . '. Nothing is cancelled until you finish on the My Bookings page.',
            'modify_booking' => 'Request a date change for booking ' . $ref . ($pk ? ' (' . $pk . ')' : '') . '. Nothing changes until you submit it on the My Bookings page.',
            'apply_coupon' => 'Use coupon ' . ($params['code'] ?? '') . '. Coupons are checked and applied at checkout.',
            default => 'Action',
        };
    }

    /** @return array{ok:bool,reason?:string,proposal?:array} */
    public static function propose(PDO $db, string $email, array $intent): array
    {
        $type = (string)($intent['type'] ?? ''); $params = (array)($intent['params'] ?? []);
        if ($email === '' || $email === 'guest' || !in_array($type, self::TYPES, true)) return ['ok' => false, 'reason' => 'invalid'];
        try {
            $booking = null;
            if (in_array($type, ['cancel_booking', 'modify_booking'], true)) {
                $st = $db->prepare('SELECT b.BookingId, b.status, b.ToDate, p.PackageName FROM tblbooking b LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId WHERE b.BookingId = ? AND b.UserEmail = ? LIMIT 1');
                $st->execute([(int)($params['booking_id'] ?? 0), $email]);
                $booking = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$booking) return ['ok' => false, 'reason' => 'not_found'];            // someone else's booking looks exactly like a missing one
                if ((int)$booking['status'] === 2) return ['ok' => false, 'reason' => 'already_cancelled'];
                $params = ['booking_id' => (int)$booking['BookingId']];
            } else {
                $params = ['code' => substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($params['code'] ?? ''))) ?? '', 0, 20)];
                if ($params['code'] === '') return ['ok' => false, 'reason' => 'invalid'];
            }
            // Times are computed in PHP and bound (not DATE_SUB/NOW) so the whole flow behaves identically on every SQL driver.
            $recent = $db->prepare("SELECT COUNT(*) FROM tbl_ai_action_proposals WHERE UserEmail = ? AND CreatedAt > ?");
            $recent->execute([$email, date('Y-m-d H:i:s', time() - 3600)]);
            $key = hash('sha256', $email . '|' . $type . '|' . json_encode($params) . '|' . date('Y-m-d'));
            $ex = $db->prepare('SELECT * FROM tbl_ai_action_proposals WHERE IdempotencyKey = ?'); $ex->execute([$key]);
            if ($row = $ex->fetch(PDO::FETCH_ASSOC)) {
                if ($row['Status'] === 'proposed' && strtotime((string)$row['ExpiresAt']) > time()) return ['ok' => true, 'proposal' => self::publicView($row)];
                if ($row['Status'] !== 'proposed') return ['ok' => false, 'reason' => 'already_decided'];
                $exp = date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60);
                $db->prepare("UPDATE tbl_ai_action_proposals SET ExpiresAt = ? WHERE ProposalId = ? AND Status = 'proposed'")->execute([$exp, (int)$row['ProposalId']]);
                $row['ExpiresAt'] = $exp;
                return ['ok' => true, 'proposal' => self::publicView($row)];
            }
            if ((int)$recent->fetchColumn() >= self::MAX_OPEN_PER_HOUR) return ['ok' => false, 'reason' => 'rate_limited'];
            $summary = self::summary($type, $params, $booking);
            $db->prepare("INSERT INTO tbl_ai_action_proposals (UserEmail, ActionType, Params, Summary, IdempotencyKey, Status, CreatedAt, ExpiresAt) VALUES (?,?,?,?,?, 'proposed', ?, ?)")
               ->execute([$email, $type, json_encode($params), mb_substr($summary, 0, 300), $key, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60)]);
            $id = (int)$db->lastInsertId();
            return ['ok' => true, 'proposal' => ['id' => $id, 'type' => $type, 'summary' => $summary, 'expires_in_min' => self::TTL_MINUTES, 'status' => 'proposed']];
        } catch (Throwable $e) {
            error_log('[TMS AI action propose] ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'unavailable'];
        }
    }

    private static function publicView(array $row): array
    {
        return ['id' => (int)$row['ProposalId'], 'type' => $row['ActionType'], 'summary' => $row['Summary'], 'status' => $row['Status'],
                'expires_in_min' => max(1, (int)ceil((strtotime((string)$row['ExpiresAt']) - time()) / 60))];
    }

    /**
     * The visitor pressed Confirm. Re-checks everything under a row lock. Never performs the action itself unless an executor was registered.
     * @return array{ok:bool,status:string,message:string,next_url?:string,executed?:bool}
     */
    public static function confirm(PDO $db, int $id, string $email): array
    {
        if ($email === '' || $email === 'guest' || $id <= 0) return ['ok' => false, 'status' => 'denied', 'message' => 'Please sign in.'];
        try {
            $own = !$db->inTransaction();
            if ($own) $db->beginTransaction();
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $db->prepare('SELECT * FROM tbl_ai_action_proposals WHERE ProposalId = ? AND UserEmail = ?' . $lock);
            $st->execute([$id, $email]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $fail = function (string $status, string $msg) use ($db, $own) { if ($own && $db->inTransaction()) $db->rollBack(); return ['ok' => false, 'status' => $status, 'message' => $msg]; };
            if (!$row) return $fail('not_found', 'This request was not found.');
            if ($row['Status'] !== 'proposed') return $fail('already_decided', 'This request has already been handled.');
            if (strtotime((string)$row['ExpiresAt']) < time()) {
                $db->prepare("UPDATE tbl_ai_action_proposals SET Status = 'expired', DecidedAt = ? WHERE ProposalId = ?")->execute([date('Y-m-d H:i:s'), $id]);
                if ($own) $db->commit();
                return ['ok' => false, 'status' => 'expired', 'message' => 'This request has expired. Ask again if you still want to do it.'];
            }
            $params = json_decode((string)$row['Params'], true) ?: [];
            if (in_array($row['ActionType'], ['cancel_booking', 'modify_booking'], true)) {
                $b = $db->prepare('SELECT status FROM tblbooking WHERE BookingId = ? AND UserEmail = ?' . $lock);
                $b->execute([(int)($params['booking_id'] ?? 0), $email]);
                $bs = $b->fetchColumn();
                if ($bs === false) return $fail('not_found', 'That booking was not found on your account.');
                if ((int)$bs === 2) return $fail('already_cancelled', 'That booking is already cancelled.');
            }
            $executed = false; $message = '';
            if (isset(self::$executors[$row['ActionType']])) {
                $r = (self::$executors[$row['ActionType']])($db, $row);
                if (empty($r['ok'])) return $fail('failed', (string)($r['message'] ?? 'The action could not be completed.'));
                $executed = true; $message = (string)($r['message'] ?? 'Done.');
            }
            $next = self::HANDOFF[$row['ActionType']] ?? 'tour-history.php';
            if (!$executed) $message = 'Nothing has been changed yet. Open the page below to finish; it will ask you to confirm there.';
            $db->prepare("UPDATE tbl_ai_action_proposals SET Status = ?, DecidedAt = ?, Result = ? WHERE ProposalId = ?")
               ->execute([$executed ? 'executed' : 'confirmed', date('Y-m-d H:i:s'), mb_substr($executed ? $message : 'handed_off:' . $next, 0, 300), $id]);
            if ($own) $db->commit();
            return ['ok' => true, 'status' => $executed ? 'executed' : 'confirmed', 'message' => $message, 'next_url' => $next, 'executed' => $executed];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[TMS AI action confirm] ' . $e->getMessage());
            return ['ok' => false, 'status' => 'error', 'message' => 'This could not be processed right now.'];
        }
    }

    public static function dismiss(PDO $db, int $id, string $email): bool
    {
        if ($email === '' || $email === 'guest' || $id <= 0) return false;
        try {
            $st = $db->prepare("UPDATE tbl_ai_action_proposals SET Status = 'dismissed', DecidedAt = ? WHERE ProposalId = ? AND UserEmail = ? AND Status = 'proposed'");
            $st->execute([date('Y-m-d H:i:s'), $id, $email]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) { return false; }
    }

    /** Text added to the model's context when a proposal was prepared (or could not be). The model only phrases it. */
    public static function modelNote(array $intent, array $res): string
    {
        if (!empty($res['ok'])) {
            return 'ACTION PREPARED BY THE SERVER, NOT PERFORMED: "' . $res['proposal']['summary'] . '". A confirmation card with a Confirm button is shown under your reply. '
                 . 'Tell the visitor in one or two sentences that nothing has been changed yet and that pressing Confirm takes them to the page where they finish it. Do not say it is done, cancelled, refunded or applied.';
        }
        return match ($res['reason'] ?? '') {
            'not_found' => 'The booking number the visitor gave was not found on THEIR account. Say you cannot find that booking under their account and ask them to check the number on the My Bookings page. Do not reveal whether it exists for anyone else.',
            'already_cancelled' => 'That booking is already cancelled. Say so; nothing more is needed.',
            'rate_limited' => 'Too many action requests in the last hour. Ask the visitor to use the My Bookings page directly.',
            'already_decided' => 'A request for this was already handled today. Point the visitor to the My Bookings page.',
            default => 'The action could not be prepared right now. Point the visitor to the My Bookings page; do not claim anything was done.',
        };
    }
}
