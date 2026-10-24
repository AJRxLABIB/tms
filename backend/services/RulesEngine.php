<?php
/**
 * RulesEngine — controlled operational automation.
 *
 * SAFETY MODEL: a rule is (predefined trigger) -> (predefined action) with a few validated numeric/text parameters.
 * There is no shell, no SQL and no PHP supplied by rule authors: trigger queries and action code live in this file;
 * rule rows only choose among them. Message text is a template with {placeholders} filled from scalar context values
 * and is only ever delivered as text (in-app notification / system event), never evaluated.
 *
 *  - Idempotent per (rule, subject, window): the unique key on tbl_workflow_runs means a repeat evaluation inside the
 *    window does nothing; a FAILED run is retried until the rule's max_attempts.
 *  - Dry run: records a 'dry_run' audit row (separate dry_run=1 key) and performs NO action.
 *  - Rules are created disabled; an admin enables them explicitly.
 *
 * Tables: database/migrations/017_workflow_rules.sql. Run via scripts/run_jobs.php --rules (or call evaluate()).
 */
class RulesEngine
{
    public const TRIGGERS = ['overdue_refund', 'incomplete_travel_details', 'supplier_sync_failure', 'repeated_failed_job', 'booking_status_change'];
    public const ACTIONS  = ['notify_admin', 'notify_customer', 'notify_supplier', 'escalate'];
    private const MAX_SUBJECTS_PER_RULE = 200;

    /** Validate and normalise a rule definition. @return array{ok:bool, errors:string[], rule:array} */
    public static function validateRule(array $in): array
    {
        $err = [];
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) $err[] = 'name is required (max 120 characters)';
        $trigger = (string)($in['trigger_type'] ?? '');
        $action  = (string)($in['action_type'] ?? '');
        if (!in_array($trigger, self::TRIGGERS, true)) $err[] = 'unknown trigger_type';
        if (!in_array($action, self::ACTIONS, true)) $err[] = 'unknown action_type';
        if ($action === 'notify_customer' && !in_array($trigger, ['overdue_refund', 'incomplete_travel_details', 'booking_status_change'], true)) {
            $err[] = 'notify_customer needs a trigger that identifies a customer';
        }
        $p = is_array($in['params'] ?? null) ? $in['params'] : [];
        $clean = [];
        foreach (['days', 'days_before', 'min_attempts', 'lookback_hours'] as $k) {
            if (isset($p[$k])) $clean[$k] = max(1, min(365, (int)$p[$k]));
        }
        if (isset($p['message'])) {
            $m = trim((string)$p['message']);
            if (mb_strlen($m) > 300) $err[] = 'message too long (max 300)';
            $clean['message'] = strip_tags($m);
        }
        $window = max(5, min(43200, (int)($in['window_minutes'] ?? 1440)));
        $maxAtt = max(1, min(10, (int)($in['max_attempts'] ?? 3)));
        return ['ok' => !$err, 'errors' => $err, 'rule' => [
            'name' => $name, 'trigger_type' => $trigger, 'action_type' => $action, 'params' => $clean,
            'window_minutes' => $window, 'max_attempts' => $maxAtt, 'enabled' => !empty($in['enabled']) ? 1 : 0,
        ]];
    }

    /** Create a rule (always disabled until an admin enables it). Returns new id or null with $errors filled. */
    public static function createRule(PDO $dbh, array $in, string $adminName, ?array &$errors = null): ?int
    {
        $v = self::validateRule($in);
        if (!$v['ok']) { $errors = $v['errors']; return null; }
        $r = $v['rule'];
        $dbh->prepare("INSERT INTO tbl_workflow_rules (name, trigger_type, action_type, params, window_minutes, max_attempts, enabled, created_by)
                       VALUES (?,?,?,?,?,?,0,?)")
            ->execute([$r['name'], $r['trigger_type'], $r['action_type'], json_encode($r['params']), $r['window_minutes'], $r['max_attempts'], mb_substr($adminName, 0, 100)]);
        return (int)$dbh->lastInsertId();
    }

    public static function setEnabled(PDO $dbh, int $ruleId, bool $enabled): void
    {
        $dbh->prepare('UPDATE tbl_workflow_rules SET enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $ruleId]);
    }

    /**
     * Evaluate enabled rules (or one rule by id, even if disabled — useful for a dry run before enabling).
     * @return array{rules:int, matched:int, acted:int, skipped:int, failed:int, dry:int}
     */
    public static function evaluate(PDO $dbh, bool $dryRun = false, ?int $onlyRuleId = null): array
    {
        $sum = ['rules' => 0, 'matched' => 0, 'acted' => 0, 'skipped' => 0, 'failed' => 0, 'dry' => 0];
        $sql = 'SELECT * FROM tbl_workflow_rules WHERE ' . ($onlyRuleId ? 'id = ' . (int)$onlyRuleId : 'enabled = 1');
        foreach ($dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $sum['rules']++;
            $params = json_decode((string)$rule['params'], true);
            $params = is_array($params) ? $params : [];
            try { $subjects = self::subjectsFor($dbh, (string)$rule['trigger_type'], $params, (int)$rule['window_minutes']); }
            catch (Throwable $e) { error_log('[TMS Rules] trigger query failed for rule ' . $rule['id'] . ': ' . $e->getMessage()); $sum['failed']++; continue; }
            $windowKey = (string)intdiv(time(), max(60, (int)$rule['window_minutes'] * 60));
            foreach ($subjects as [$subjectKey, $ctx]) {
                $sum['matched']++;
                self::runOne($dbh, $rule, $params, $subjectKey, $ctx, $windowKey, $dryRun, $sum);
            }
        }
        return $sum;
    }

    private static function runOne(PDO $dbh, array $rule, array $params, string $subjectKey, array $ctx, string $windowKey, bool $dry, array &$sum): void
    {
        $subjectKey = mb_substr($subjectKey, 0, 120);
        $ins = $dbh->prepare("INSERT IGNORE INTO tbl_workflow_runs (rule_id, subject_key, window_key, dry_run, status, attempts) VALUES (?,?,?,?, 'pending', 0)");
        $ins->execute([(int)$rule['id'], $subjectKey, $windowKey, $dry ? 1 : 0]);
        $fresh = $ins->rowCount() > 0;
        $q = $dbh->prepare('SELECT id, status, attempts FROM tbl_workflow_runs WHERE rule_id = ? AND subject_key = ? AND window_key = ? AND dry_run = ?');
        $q->execute([(int)$rule['id'], $subjectKey, $windowKey, $dry ? 1 : 0]);
        $run = $q->fetch(PDO::FETCH_ASSOC);
        if (!$run) { $sum['failed']++; return; }
        // Already handled in this window (done / dry_run), or out of retries.
        if (!$fresh && ($run['status'] !== 'failed' || (int)$run['attempts'] >= (int)$rule['max_attempts'])) { $sum['skipped']++; return; }
        // Claim this attempt atomically so two workers do not both act.
        $claim = $dbh->prepare("UPDATE tbl_workflow_runs SET attempts = attempts + 1, status = 'pending' WHERE id = ? AND status IN ('pending','failed') AND attempts = ?");
        $claim->execute([(int)$run['id'], (int)$run['attempts']]);
        if ($claim->rowCount() === 0) { $sum['skipped']++; return; }

        $message = self::render((string)($params['message'] ?? self::defaultMessage((string)$rule['trigger_type'])), $ctx + ['subject' => $subjectKey]);
        if ($dry) {
            $dbh->prepare("UPDATE tbl_workflow_runs SET status = 'dry_run', detail = ? WHERE id = ?")
                ->execute([mb_substr('would ' . $rule['action_type'] . ': ' . $message, 0, 490), (int)$run['id']]);
            $sum['dry']++;
            return;
        }
        try {
            self::act($dbh, (string)$rule['action_type'], $rule, $ctx, $message);
            $dbh->prepare("UPDATE tbl_workflow_runs SET status = 'done', detail = ? WHERE id = ?")->execute([mb_substr($message, 0, 490), (int)$run['id']]);
            $sum['acted']++;
        } catch (Throwable $e) {
            $dbh->prepare("UPDATE tbl_workflow_runs SET status = 'failed', detail = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 490), (int)$run['id']]);
            $sum['failed']++;
        }
    }

    /** @return array<int, array{0:string,1:array<string,scalar>}> subject key + scalar context */
    private static function subjectsFor(PDO $dbh, string $trigger, array $p, int $windowMinutes): array
    {
        $lim = self::MAX_SUBJECTS_PER_RULE;
        $out = [];
        switch ($trigger) {
            case 'overdue_refund': {
                $days = (int)($p['days'] ?? 3);
                $st = $dbh->prepare(
                    "SELECT r.RefundId, r.AmountMinor, p.BookingId, b.UserEmail
                     FROM tblpayment_refunds r JOIN tblpayments p ON p.PaymentId = r.PaymentId LEFT JOIN tblbooking b ON b.BookingId = p.BookingId
                     WHERE r.Status = 'pending' AND r.CreatedAt < DATE_SUB(NOW(), INTERVAL :d DAY) ORDER BY r.RefundId LIMIT $lim");
                $st->bindValue(':d', max(1, $days), PDO::PARAM_INT); $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['refund:' . $r['RefundId'], ['refund_id' => (int)$r['RefundId'], 'booking_id' => (int)$r['BookingId'], 'amount' => number_format((int)$r['AmountMinor'] / 100, 2), 'days' => $days, 'email' => (string)$r['UserEmail']]];
                }
                break;
            }
            case 'incomplete_travel_details': {
                $before = (int)($p['days_before'] ?? 14);
                $st = $dbh->prepare(
                    "SELECT b.BookingId, b.UserEmail, b.FromDate FROM tblbooking b
                     WHERE b.status = 1 AND b.FromDate REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'
                       AND STR_TO_DATE(LEFT(b.FromDate, 10), '%Y-%m-%d') BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :d DAY)
                       AND NOT EXISTS (SELECT 1 FROM tbltraveldocuments t WHERE t.UserEmail = b.UserEmail)
                     ORDER BY b.BookingId LIMIT $lim");
                $st->bindValue(':d', max(1, $before), PDO::PARAM_INT); $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['booking:' . $r['BookingId'] . ':details', ['booking_id' => (int)$r['BookingId'], 'email' => (string)$r['UserEmail'], 'date' => substr((string)$r['FromDate'], 0, 10), 'days' => $before]];
                }
                break;
            }
            case 'supplier_sync_failure': {
                $hours = (int)($p['lookback_hours'] ?? 24);
                $st = $dbh->prepare("SELECT id, event_type, message FROM tbl_system_events WHERE category = 'supplier_sync' AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL :h HOUR) ORDER BY id LIMIT $lim");
                $st->bindValue(':h', max(1, $hours), PDO::PARAM_INT); $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['sysevent:' . $r['id'], ['event_id' => (int)$r['id'], 'event' => (string)$r['event_type'], 'detail' => (string)$r['message']]];
                }
                break;
            }
            case 'repeated_failed_job': {
                $min = (int)($p['min_attempts'] ?? 3);
                $st = $dbh->prepare("SELECT id, event_type, attempts, last_error FROM tbl_outbox_events WHERE status IN ('failed','dead') AND attempts >= :m ORDER BY id LIMIT $lim");
                $st->bindValue(':m', max(1, $min), PDO::PARAM_INT); $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['outbox:' . $r['id'], ['event_id' => (int)$r['id'], 'event' => (string)$r['event_type'], 'attempts' => (int)$r['attempts'], 'detail' => (string)$r['last_error']]];
                }
                break;
            }
            case 'booking_status_change': {
                $st = $dbh->prepare("SELECT BookingId, UserEmail, status FROM tblbooking WHERE UpdationDate > DATE_SUB(NOW(), INTERVAL :m MINUTE) AND status IN (1,2) ORDER BY BookingId LIMIT $lim");
                $st->bindValue(':m', max(5, $windowMinutes), PDO::PARAM_INT); $st->execute();
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $label = (int)$r['status'] === 1 ? 'confirmed' : 'cancelled';
                    $out[] = ['booking:' . $r['BookingId'] . ':' . $label, ['booking_id' => (int)$r['BookingId'], 'email' => (string)$r['UserEmail'], 'status' => $label]];
                }
                break;
            }
        }
        return $out;
    }

    /** Predefined action implementations. Delivery is in-app notification or the system-event log only. */
    private static function act(PDO $dbh, string $action, array $rule, array $ctx, string $message): void
    {
        switch ($action) {
            case 'notify_customer':
                $email = (string)($ctx['email'] ?? '');
                if ($email === '') throw new RuntimeException('no customer email for this subject');
                if (!function_exists('tms_notify')) throw new RuntimeException('tms_notify unavailable');
                tms_notify($dbh, $email, $message, 'tour-history.php');
                return;
            case 'notify_supplier':
                // Suppliers have no in-app inbox yet: record it for the supplier portal / ops to pick up. Honest limit.
                self::sysEvent($dbh, 'supplier_notice', true, $message);
                return;
            case 'escalate':
                self::sysEvent($dbh, 'escalation', false, '[ESCALATED] ' . $message);   // success=0 so it surfaces as a problem on health pages
                return;
            case 'notify_admin':
            default:
                self::sysEvent($dbh, 'admin_notice', true, $message);
        }
    }

    private static function sysEvent(PDO $dbh, string $type, bool $ok, string $msg): void
    {
        if (!function_exists('tms_log_system_event')) throw new RuntimeException('tms_log_system_event unavailable');
        // tms_log_system_event swallows its own errors (it logs them), so a failed write is not detectable here.
        tms_log_system_event($dbh, 'workflow', $type, $ok, $msg);
    }

    /** Fill {placeholders} from scalar context values only; unknown placeholders become empty. */
    public static function render(string $tpl, array $ctx): string
    {
        $map = [];
        foreach ($ctx as $k => $v) if (is_scalar($v)) $map['{' . $k . '}'] = (string)$v;
        $out = strtr($tpl, $map);
        $out = preg_replace('/\{[a-z_]+\}/', '', $out) ?? $out;
        return trim(strip_tags($out));
    }

    private static function defaultMessage(string $trigger): string
    {
        return match ($trigger) {
            'overdue_refund'            => 'Refund #{refund_id} for booking #{booking_id} ({amount}) has been pending for over {days} days.',
            'incomplete_travel_details' => 'Booking #{booking_id} departs on {date}: please upload your travel documents in your wallet.',
            'supplier_sync_failure'     => 'Supplier sync problem: {event} {detail}',
            'repeated_failed_job'       => 'Background job #{event_id} ({event}) failed {attempts} times: {detail}',
            'booking_status_change'     => 'Your booking #{booking_id} is now {status}.',
            default                     => 'Workflow notice for {subject}.',
        };
    }
}
