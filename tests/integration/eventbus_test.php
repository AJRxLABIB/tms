<?php
/**
 * EventBus + RulesEngine integration test against the REAL configured database (needs migrations 016 and 017 applied:
 * `php database/migrate.php`). Creates only rows tagged EbTest* / "EB-TEST" and deletes them afterwards. CLI only.
 *   php tests/integration/eventbus_test.php
 * Only EbTest* events are claimed (typePrefix), so real queued events are never touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/EventBus.php';
require_once 'backend/services/RulesEngine.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 66) . $d . "\n"; }

foreach (['tbl_outbox_events', 'tbl_outbox_deliveries', 'tbl_workflow_rules', 'tbl_workflow_runs'] as $tbl) {
    try { $dbh->query("SELECT 1 FROM `$tbl` LIMIT 1"); }
    catch (Throwable $e) { echo "SKIP: table $tbl missing -- run `php database/migrate.php` first (migrations 016, 017).\n"; exit(2); }
}

function cleanup(PDO $d): void {
    $d->exec("DELETE FROM tbl_outbox_deliveries WHERE event_id IN (SELECT id FROM tbl_outbox_events WHERE event_type LIKE 'EbTest%')");
    $d->exec("DELETE FROM tbl_outbox_events WHERE event_type LIKE 'EbTest%'");
    $d->exec("DELETE FROM tbl_workflow_runs WHERE rule_id IN (SELECT id FROM tbl_workflow_rules WHERE name LIKE 'EB-TEST%')");
    $d->exec("DELETE FROM tbl_workflow_rules WHERE name LIKE 'EB-TEST%'");
    try { $d->exec("DELETE FROM tbl_system_events WHERE category = 'workflow' AND message LIKE 'EB-TEST%'"); } catch (Throwable $e) {}
}
function ev(PDO $d, int $id): array { $s = $d->prepare('SELECT * FROM tbl_outbox_events WHERE id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }

class EbAll implements EventConsumer {
    public int $calls = 0; public bool $fail;
    public function __construct(private string $n, bool $fail = false) { $this->fail = $fail; }
    public function name(): string { return $this->n; }
    public function handles(): array { return ['*']; }
    public function handle(array $e): void { $this->calls++; if ($this->fail) throw new RuntimeException('boom'); }
}

cleanup($dbh);
EventBus::resetConsumers();

// 1. publish idempotency
$id1 = EventBus::publish($dbh, 'EbTestA', 'booking', 1, ['x' => 1], 'ebtest-key-1');
$id1b = EventBus::publish($dbh, 'EbTestA', 'booking', 1, ['x' => 2], 'ebtest-key-1');
t('publish returns an id', $id1 > 0);
t('same idempotency key returns the same event, no second row', $id1 === $id1b && (int)$dbh->query("SELECT COUNT(*) FROM tbl_outbox_events WHERE idempotency_key='ebtest-key-1'")->fetchColumn() === 1);

// 2. claim -> consumer -> done; a second claim finds nothing
$ok = new EbAll('eb_ok'); EventBus::registerConsumer($ok);
$c = EventBus::runBatch($dbh, 'test', 20, 'EbTest');
t('batch processes the event', $c['done'] === 1 && ev($dbh, $id1)['status'] === 'done');
t('consumer ran once and a delivery row exists', $ok->calls === 1 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_outbox_deliveries WHERE event_id=$id1 AND consumer='eb_ok' AND status='done'")->fetchColumn() === 1);
$c2 = EventBus::runBatch($dbh, 'test', 20, 'EbTest');
t('a done event is not processed again', $c2['done'] === 0 && $ok->calls === 1);

// 3. failure -> failed with backoff; attempts counted
EventBus::resetConsumers(); $bad = new EbAll('eb_bad', true); EventBus::registerConsumer($bad);
$id2 = EventBus::publish($dbh, 'EbTestB', 'booking', 2, [], 'ebtest-key-2', 2);
$c = EventBus::runBatch($dbh, 'test', 20, 'EbTestB');
$e2 = ev($dbh, $id2);
t('failing consumer -> status failed, attempts=1, retry scheduled in the future', $c['failed'] === 1 && $e2['status'] === 'failed' && (int)$e2['attempts'] === 1 && strtotime($e2['available_at']) > time() + 10, $e2['last_error'] ?? '');
$c = EventBus::runBatch($dbh, 'test', 20, 'EbTestB');
t('not retried before its backoff time', $c['failed'] === 0 && $bad->calls === 1);
$dbh->prepare('UPDATE tbl_outbox_events SET available_at = NOW() WHERE id = ?')->execute([$id2]);
$c = EventBus::runBatch($dbh, 'test', 20, 'EbTestB');
t('after max_attempts the event is dead-lettered', $c['dead'] === 1 && ev($dbh, $id2)['status'] === 'dead');
t('backoff grows exponentially and is capped', EventBus::backoffSeconds(1) === 30 && EventBus::backoffSeconds(3) === 120 && EventBus::backoffSeconds(30) === EventBus::MAX_BACKOFF_SECONDS);

// 4. replay: consumers that already succeeded are NOT run again
EventBus::resetConsumers(); $good = new EbAll('eb_good'); $flaky = new EbAll('eb_flaky', true);
EventBus::registerConsumer($good); EventBus::registerConsumer($flaky);
$id3 = EventBus::publish($dbh, 'EbTestC', 'booking', 3, [], 'ebtest-key-3', 3);
EventBus::runBatch($dbh, 'test', 20, 'EbTestC');
$dbh->prepare('UPDATE tbl_outbox_events SET available_at = NOW() WHERE id = ?')->execute([$id3]);
$flaky->fail = false;
EventBus::runBatch($dbh, 'test', 20, 'EbTestC');
t('retry delivers to the failed consumer only; the successful one ran once', $good->calls === 1 && $flaky->calls === 2 && ev($dbh, $id3)['status'] === 'done');
t('replay refuses a done event', EventBus::replay($dbh, $id3) === false);
t('replay re-queues a dead event', EventBus::replay($dbh, $id2) === true && ev($dbh, $id2)['status'] === 'pending' && (int)ev($dbh, $id2)['attempts'] === 0);

// 5. stuck worker recovery
$id4 = EventBus::publish($dbh, 'EbTestD', 'booking', 4, [], 'ebtest-key-4');
$dbh->prepare("UPDATE tbl_outbox_events SET status='processing', locked_by='dead-worker', locked_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE), attempts = 1 WHERE id = ?")->execute([$id4]);
EventBus::recoverStale($dbh, 10);
$e4 = ev($dbh, $id4);
t('an event stuck in processing is released for retry', $e4['status'] === 'failed' && $e4['locked_by'] === null);

// 6. rules engine
$bogus = RulesEngine::validateRule(['name' => 'x', 'trigger_type' => 'run_shell', 'action_type' => 'notify_admin']);
t('rule with an unknown trigger is rejected', !$bogus['ok']);
$bogus2 = RulesEngine::validateRule(['name' => 'x', 'trigger_type' => 'overdue_refund', 'action_type' => 'exec']);
t('rule with an unknown action is rejected', !$bogus2['ok']);
$errs = null;
$rid = RulesEngine::createRule($dbh, ['name' => 'EB-TEST failed jobs', 'trigger_type' => 'repeated_failed_job', 'action_type' => 'notify_admin',
    'params' => ['min_attempts' => 1, 'message' => 'EB-TEST job {event_id} {event}'], 'window_minutes' => 60, 'max_attempts' => 2], 'eb-test', $errs);
t('valid rule is created disabled', $rid > 0 && (int)$dbh->query("SELECT enabled FROM tbl_workflow_rules WHERE id=$rid")->fetchColumn() === 0);
$dbh->prepare('UPDATE tbl_outbox_events SET status = \'dead\', attempts = 6 WHERE id = ?')->execute([$id2]);   // a subject for the trigger
$dry = RulesEngine::evaluate($dbh, true, $rid);
$nSys = (int)$dbh->query("SELECT COUNT(*) FROM tbl_system_events WHERE category='workflow' AND message LIKE 'EB-TEST%'")->fetchColumn();
t('dry run records dry_run audit rows and performs no action', $dry['dry'] >= 1 && $dry['acted'] === 0 && $nSys === 0);
$real1 = RulesEngine::evaluate($dbh, false, $rid);
$real2 = RulesEngine::evaluate($dbh, false, $rid);
t('real run acts once per subject', $real1['acted'] >= 1 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_system_events WHERE category='workflow' AND message LIKE 'EB-TEST%'")->fetchColumn() >= 1);
t('second run in the same window is idempotent (nothing acted)', $real2['acted'] === 0 && $real2['skipped'] >= 1);
t('template renders placeholders and strips markup', RulesEngine::render('Hi {a} <b>{b}</b> {zzz}', ['a' => 'x', 'b' => 'y']) === 'Hi x y');

cleanup($dbh);
t('temporary rows cleaned up', (int)$dbh->query("SELECT COUNT(*) FROM tbl_outbox_events WHERE event_type LIKE 'EbTest%'")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
