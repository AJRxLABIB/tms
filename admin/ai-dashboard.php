<?php
/**
 * admin/ai-dashboard.php — AI Analytics & Monitoring Dashboard
 * Admin-only view of AI system health, usage, insights, and fraud flags.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('../backend/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['ai_manager', 'manager']); // Round 20 RBAC (item 46)

require_once '../backend/ai/AIClient.php';
require_once '../backend/ai/SentimentAnalyser.php';
require_once '../backend/ai/FraudDetector.php';
require_once '../backend/ai/AIEvaluator.php';


// ── Engine controls (AIConfig). Admin-only, CSRF-protected, bounded and logged; post/redirect/get. ──
require_once '../backend/ai/AIConfig.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_cfg_action'])) {
    tms_csrf_validate();
    $by = (string)($_SESSION['alogin'] ?? 'admin'); $errs = [];
    if ($_POST['ai_cfg_action'] === 'reset') {
        foreach (array_keys(AIConfig::SPEC) as $n) AIConfig::reset($dbh, $n, $by);
        $_SESSION['ai_cfg_flash'] = 'All engine controls restored to defaults.';
    } else {
        foreach (AIConfig::SPEC as $n => $sp) {
            $raw = $sp[0] === 'bool' ? (isset($_POST['cfg'][$n]) ? '1' : '0') : ($_POST['cfg'][$n] ?? '');
            $r = AIConfig::set($dbh, $n, $raw, $by);
            if (!$r['ok']) $errs[] = $sp[4] . ': ' . $r['error'];
        }
        $_SESSION['ai_cfg_flash'] = $errs ? 'Some values were rejected (' . implode('; ', $errs) . '); the others were saved.' : 'Engine controls saved.';
    }
    header('Location: ai-dashboard.php'); exit;
}
// ── Embedding index (bounded, admin-triggered). Needs AI_EMBED_MODEL; never runs on page load. ──
require_once '../backend/ai/rag/EmbeddingStore.php';
require_once '../backend/ai/rag/EmbeddingReranker.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_embed_action'])) {
    tms_csrf_validate();
    $rrx = EmbeddingReranker::fromEnv(15);
    if (!$rrx) { $_SESSION['ai_cfg_flash'] = 'No embedding model is configured (AI_EMBED_MODEL in .env), so there is nothing to index.'; }
    else {
        $sr = EmbeddingStore::syncCatalogue($dbh, $rrx, 40, 20.0);
        $_SESSION['ai_cfg_flash'] = 'Indexed ' . (int)$sr['embedded'] . ' package(s), ' . (int)$sr['unchanged'] . ' already current, ' . (int)$sr['failed'] . ' failed, ' . (int)$sr['remaining'] . ' remaining' . (!empty($sr['stopped']) ? ' (stopped: ' . $sr['stopped'] . ')' : '') . '.';
    }
    header('Location: ai-dashboard.php'); exit;
}
$cfgFlash = (string)($_SESSION['ai_cfg_flash'] ?? ''); unset($_SESSION['ai_cfg_flash']);

// Round 35 (item 22, "AI Evaluation suite"), extended Round 95 with 3 more
// content-accuracy cases and Round 98 with a 4th (weather_accuracy):
// admin-triggered only, never automatic -- this makes 13 real AI calls
// (real quota cost -- weather_accuracy runs a full second itinerary
// generation to check against real weather data, so it's two itinerary-
// shaped calls now, not one), so it must never run on a normal page load.
// See AIEvaluator.php's own docblock for what it covers and what it
// deliberately doesn't.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_eval_suite'])) {
    tms_csrf_validate();
    // Two of the 13 cases (itinerary, weather_accuracy) can each legitimately
    // take up to ~90s (see ItineraryPlanner's own extended budget for that
    // workload), and this runs all 13 cases in sequence with a small
    // deliberate gap between each (see AIEvaluator::runAll()) -- a default
    // 30s max_execution_time would kill this mid-run.
    set_time_limit(450);
    $evalSummary = (new AIEvaluator($dbh))->runAll();
    header('Content-Type: application/json');
    echo json_encode($evalSummary);
    exit;
}

// Travel Alert System (master prompt item 17). tbl_safety_alerts and this
// page's own read-side display (further down) already existed, but the
// "+ Add Alert" button's JS posted add_alert=1 to nowhere -- there was no
// server-side handler at all, so clicking it silently did nothing (Rule 7:
// a control that does nothing is a fake feature). Adding the real write
// path here, plus a Dismiss action that didn't exist either.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_alert'])) {
    tms_csrf_validate();
    $destination = trim($_POST['destination'] ?? '');
    $alertType   = trim($_POST['alert_type'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $severity    = in_array($_POST['severity'] ?? '', ['info', 'warning', 'critical'], true) ? $_POST['severity'] : 'warning';
    $alertMsg = '';
    if ($destination === '' || $alertType === '' || $message === '') {
        $alertMsg = 'Destination, type, and message are all required -- alert not created.';
    } else {
        $dbh->prepare("INSERT INTO tbl_safety_alerts (destination, alert_type, message, severity, active) VALUES (:d, :t, :m, :s, 1)")
            ->execute([':d' => $destination, ':t' => $alertType, ':m' => $message, ':s' => $severity]);
        tms_log_activity($dbh, $_SESSION['alogin'], 'Safety Alert Created', "Destination: $destination, type: $alertType, severity: $severity");
        $alertMsg = "Alert created for {$destination}.";
    }
    header('Location: ai-dashboard.php?alert_msg=' . urlencode($alertMsg));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_alert'])) {
    tms_csrf_validate();
    $alertId = (int)$_POST['dismiss_alert'];
    $dbh->prepare("UPDATE tbl_safety_alerts SET active = 0 WHERE id = :id")->execute([':id' => $alertId]);
    tms_log_activity($dbh, $_SESSION['alogin'], 'Safety Alert Dismissed', "Alert #$alertId");
    header('Location: ai-dashboard.php?alert_msg=' . urlencode('Alert dismissed.'));
    exit;
}
$alertFlashMsg = isset($_GET['alert_msg']) ? htmlspecialchars($_GET['alert_msg'], ENT_QUOTES, 'UTF-8') : '';

// ── Load stats ────────────────────────────────────────────────────────────────
$stats = [];

// Total AI calls today
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tbl_ai_usage_log WHERE DATE(created_at)=CURDATE()");
    $stmt->execute();
    $stats['ai_calls_today'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['ai_calls_today'] = 0; }

// Total AI calls this month
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tbl_ai_usage_log WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $stmt->execute();
    $stats['ai_calls_month'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['ai_calls_month'] = 0; }

// Total AI itineraries generated
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tbl_ai_itineraries");
    $stmt->execute();
    $stats['itineraries'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['itineraries'] = 0; }

// Copilot messages
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tbl_copilot_sessions");
    $stmt->execute();
    $stats['copilot_msgs'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['copilot_msgs'] = 0; }

// Travel memories
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tbl_travel_memories");
    $stmt->execute();
    $stats['memories'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['memories'] = 0; }

// Review sentiment breakdown
try {
    $stmt = $dbh->prepare("SELECT sentiment, COUNT(*) as cnt FROM tbl_review_sentiment GROUP BY sentiment");
    $stmt->execute();
    $sentimentRows = $stmt->fetchAll(PDO::FETCH_OBJ);
    $stats['sentiment'] = ['positive'=>0,'neutral'=>0,'negative'=>0];
    foreach ($sentimentRows as $r) {
        $stats['sentiment'][$r->sentiment] = (int)$r->cnt;
    }
} catch(Exception $e) { $stats['sentiment'] = ['positive'=>0,'neutral'=>0,'negative'=>0]; }

// Top AI users
try {
    $stmt = $dbh->prepare("SELECT user_email, COUNT(*) as calls FROM tbl_ai_usage_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY user_email ORDER BY calls DESC LIMIT 10");
    $stmt->execute();
    $topUsers = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $topUsers = []; }

// Round 34 (item 21, "AI Provider Abstraction + telemetry"): per-feature
// success rate, latency and token usage from the new tbl_ai_telemetry log
// (one row per AIClient call, any provider) -- separate from tbl_ai_usage_log
// above, which only ever counted calls for rate limiting.
try {
    $stmt = $dbh->prepare(
        "SELECT feature,
                COUNT(*) AS calls,
                SUM(success) AS successes,
                ROUND(AVG(latency_ms)) AS avg_latency_ms,
                SUM(COALESCE(total_tokens,0)) AS total_tokens
         FROM tbl_ai_telemetry
         WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY feature
         ORDER BY calls DESC"
    );
    $stmt->execute();
    $telemetryByFeature = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) { $telemetryByFeature = []; }

try {
    $stmt = $dbh->prepare(
        "SELECT COUNT(*) AS calls, SUM(success) AS successes, ROUND(AVG(latency_ms)) AS avg_latency_ms
         FROM tbl_ai_telemetry WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND cached = 0"
    );
    $stmt->execute();
    $telemetrySummary = $stmt->fetch(PDO::FETCH_OBJ) ?: null;
} catch (Exception $e) { $telemetrySummary = null; }
$telemetryCalls       = $telemetrySummary ? (int)$telemetrySummary->calls : 0;
$telemetrySuccessRate = $telemetryCalls > 0 ? round(100 * (int)$telemetrySummary->successes / $telemetryCalls) : null;
$telemetryAvgLatency  = $telemetrySummary && $telemetrySummary->avg_latency_ms !== null ? (int)$telemetrySummary->avg_latency_ms : null;

// Which provider is actually live right now -- read straight from config
// rather than instantiating AIClient, since the dashboard only needs to
// display this, not call the AI.
$activeProviderName  = defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini';
if ($activeProviderName === 'openai') {
    $activeProviderModel = defined('OPENAI_MODEL') ? OPENAI_MODEL : '';
    $activeProviderReady = defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '';
} elseif ($activeProviderName === 'ollama') {
    $activeProviderModel = defined('OLLAMA_MODEL') ? OLLAMA_MODEL : '';
    $activeProviderReady = $activeProviderModel !== '';
} else {
    $activeProviderModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : '';
    $activeProviderReady = defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';
}

// Provider chain health (primary + any AI_FALLBACK_PROVIDERS) and last-24h numbers per provider from tbl_ai_telemetry.
// Read-only; never shows keys or request contents.
$chainNames = array_values(array_unique(array_filter(array_merge([$activeProviderName], array_map('trim', explode(',', defined('AI_FALLBACK_PROVIDERS') ? (string)AI_FALLBACK_PROVIDERS : ''))))));
$providerHealth = ProviderHealth::snapshot($dbh, $chainNames);
require_once dirname(__DIR__) . '/backend/ai/AITurnLog.php';
$turnStats = AITurnLog::summary($dbh, 7);
$providerStats = [];
try {
    foreach ($dbh->query("SELECT provider, COUNT(*) calls, SUM(success) ok, AVG(latency_ms) avg_ms, SUM(CASE WHEN error_message LIKE '%circuit_open%' THEN 1 ELSE 0 END) skipped
                            FROM tbl_ai_telemetry WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND cached = 0 GROUP BY provider")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // p95 per provider (small table scan, last 24 h only)
        $lat = $dbh->prepare("SELECT latency_ms FROM tbl_ai_telemetry WHERE provider = ? AND success = 1 AND cached = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY latency_ms");
        $lat->execute([$row['provider']]);
        $vals = $lat->fetchAll(PDO::FETCH_COLUMN);
        $row['p95'] = $vals ? (int)$vals[(int)max(0, ceil(0.95 * count($vals)) - 1)] : null;
        $providerStats[$row['provider']] = $row;
    }
} catch (Throwable $e) { $providerStats = []; }

// Round 35 -- eval run history for the panel below.
$recentEvalRuns = (new AIEvaluator($dbh))->recentRuns(8);

// Recent AI itineraries
try {
    $stmt = $dbh->prepare("SELECT user_email, ai_version, created_at FROM tbl_ai_itineraries ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $recentItineraries = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $recentItineraries = []; }

// Unanalysed reviews count
try {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM tblreviews r LEFT JOIN tbl_review_sentiment s ON s.review_id=r.id WHERE s.id IS NULL AND r.Status=1");
    $stmt->execute();
    $stats['unanalysed_reviews'] = (int)$stmt->fetchColumn();
} catch(Exception $e) { $stats['unanalysed_reviews'] = 0; }

// Safety alerts
try {
    $stmt = $dbh->prepare("SELECT * FROM tbl_safety_alerts WHERE active=1 ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $safetyAlerts = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $safetyAlerts = []; }

// Top chatbot quick-reply clicks (last 30 days) — shows which suggestions
// visitors actually tap, so the quick-reply set can be tuned over time.
try {
    $stmt = $dbh->prepare("SELECT Label, COUNT(*) as clicks FROM tbl_chatbot_clicks
                            WHERE ClickedAt > DATE_SUB(NOW(), INTERVAL 30 DAY)
                            GROUP BY Label ORDER BY clicks DESC LIMIT 10");
    $stmt->execute();
    $topQuickReplies = $stmt->fetchAll(PDO::FETCH_OBJ);
    $maxQrClicks = $topQuickReplies ? (int)$topQuickReplies[0]->clicks : 0;
} catch(Exception $e) { $topQuickReplies = []; $maxQrClicks = 0; }

// Handle batch sentiment analysis trigger
$batchMsg = '';
if ($_GET['action'] === 'analyse_reviews') {
    $analyser = new SentimentAnalyser($dbh);
    $count    = $analyser->batchAnalyse(20);
    $batchMsg = "Analysed {$count} reviews successfully.";
    header("Location: ai-dashboard.php?msg=" . urlencode($batchMsg));
    exit;
}
if ($_GET['msg']) $batchMsg = htmlspecialchars($_GET['msg']);

// Feature Phase 2c: issues/highlights extraction + fake-review flagging —
// surfaces data the AI was already generating but that used to be discarded.
$sentimentDetail = new SentimentAnalyser($dbh);
$topPhrases       = $sentimentDetail->getTopIssuesAndHighlights(8);
$suspiciousReviews = $sentimentDetail->getSuspiciousReviews(15);

$fraudDetector      = new FraudDetector($dbh);
$duplicateAccounts  = $fraudDetector->getDuplicateAccounts();
$suspiciousBookings = $fraudDetector->getSuspiciousBookings(20);

// ── Business Forecasts (reviewer ask: "Predicted revenue, Packages at risk
// of low occupancy, Customers likely to return, Customers likely to cancel")
// Deliberately simple, transparent heuristics over real booking data — not
// a black-box ML score that can't be explained to a stakeholder. Each query
// is commented with exactly what it measures so it's auditable.
function tms_forecast_revenue(PDO $dbh): array {
    // Average confirmed revenue per day over the last 3 full calendar months,
    // projected across the days remaining in the current month. A simple
    // moving-average trend, not a fabricated number — and it degrades
    // gracefully (returns nulls) if there's not enough history yet.
    $rows = $dbh->query(
        "SELECT DATE_FORMAT(FromDate, '%Y-%m') as ym, SUM(TotalCost) as rev
         FROM tblbooking WHERE status=1 AND FromDate >= DATE_SUB(CURDATE(), INTERVAL 4 MONTH)
         GROUP BY ym ORDER BY ym"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $priorMonths = array_slice($rows, 0, -1, true); // exclude current, in-progress month
    if (count($priorMonths) < 2) return ['available' => false];
    $avgMonthly = array_sum($priorMonths) / count($priorMonths);
    $daysInMonth = (int)date('t');
    $dayOfMonth  = (int)date('j');
    $currentMonthKey = date('Y-m');
    $soFar = (float)($rows[$currentMonthKey] ?? 0);
    $projectedRest = ($avgMonthly / $daysInMonth) * ($daysInMonth - $dayOfMonth);
    return [
        'available'      => true,
        'avg_monthly'    => round($avgMonthly),
        'so_far_this_month' => round($soFar),
        'projected_total_this_month' => round($soFar + $projectedRest),
    ];
}
function tms_packages_at_risk(PDO $dbh, int $limit = 6): array {
    // "At risk" = had confirmed bookings before (so it's a real, previously
    // viable package, not brand-new) but zero confirmed bookings in the
    // last 30 days. Avoids unfairly flagging packages that are simply new.
    return $dbh->query(
        "SELECT p.PackageId, p.PackageName, p.PackageLocation,
                COUNT(b.BookingId) as lifetime_bookings,
                MAX(b.FromDate) as last_booking_date
         FROM tbltourpackages p
         JOIN tblbooking b ON b.PackageId = p.PackageId AND b.status = 1
         WHERE p.Status = 1
         GROUP BY p.PackageId
         HAVING lifetime_bookings >= 2
            AND NOT EXISTS (
                SELECT 1 FROM tblbooking b2
                WHERE b2.PackageId = p.PackageId AND b2.status = 1
                  AND b2.FromDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            )
         ORDER BY lifetime_bookings DESC LIMIT $limit"
    )->fetchAll(PDO::FETCH_OBJ);
}
function tms_customers_cancel_risk(PDO $dbh, int $limit = 6): array {
    // Customers with a cancellation rate over 30% across at least 2
    // bookings — a real historical pattern, not a guess about intent.
    return $dbh->query(
        "SELECT UserEmail, COUNT(*) as total,
                SUM(CASE WHEN status=2 THEN 1 ELSE 0 END) as cancelled,
                ROUND(SUM(CASE WHEN status=2 THEN 1 ELSE 0 END) / COUNT(*) * 100) as cancel_rate
         FROM tblbooking GROUP BY UserEmail
         HAVING total >= 2 AND cancel_rate >= 30
         ORDER BY cancel_rate DESC, total DESC LIMIT $limit"
    )->fetchAll(PDO::FETCH_OBJ);
}
function tms_customers_likely_return(PDO $dbh, int $limit = 6): array {
    // Repeat customers: 2+ confirmed bookings with the most recent one
    // within the last 6 months (still active, not a one-time visitor who
    // churned years ago).
    return $dbh->query(
        "SELECT UserEmail, COUNT(*) as confirmed_bookings, MAX(FromDate) as last_booking
         FROM tblbooking WHERE status=1 GROUP BY UserEmail
         HAVING confirmed_bookings >= 2 AND last_booking >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         ORDER BY confirmed_bookings DESC LIMIT $limit"
    )->fetchAll(PDO::FETCH_OBJ);
}
function tms_likely_next_bookings(PDO $dbh, int $limit = 6): array {
    // "Most likely next bookings" — originally skipped for lacking a real
    // signal to base it on; revisited now that cart contents are real,
    // queryable purchase intent. A package sitting in someone's cart is
    // the strongest available signal that a booking is imminent — far
    // more honest than a trained-model prediction this app has no data to
    // support. Excludes items where the same user already has a confirmed
    // booking for that exact package (already converted, not "likely
    // next" anymore) and only looks at carts touched in the last 14 days
    // (an item added 6 months ago and forgotten isn't "about to book").
    return $dbh->query(
        "SELECT c.UserEmail, p.PackageName, c.NoOfPersons, c.CreatedAt,
                COALESCE(c.DynamicPrice, p.PackagePrice) * c.NoOfPersons AS EstValue
         FROM tblcart c
         JOIN tbltourpackages p ON p.PackageId = c.PackageId
         WHERE c.CreatedAt >= DATE_SUB(NOW(), INTERVAL 14 DAY)
           AND NOT EXISTS (
               SELECT 1 FROM tblbooking b
               WHERE b.UserEmail = c.UserEmail AND b.PackageId = c.PackageId AND b.status = 1
           )
         ORDER BY c.CreatedAt DESC LIMIT $limit"
    )->fetchAll(PDO::FETCH_OBJ);
}
$revenueForecast   = tms_forecast_revenue($dbh);
$packagesAtRisk    = tms_packages_at_risk($dbh);
$customersCancelRisk = tms_customers_cancel_risk($dbh);
$customersReturning  = tms_customers_likely_return($dbh);
$likelyNextBookings  = tms_likely_next_bookings($dbh);

// Handle AI insights generation
$aiInsights = '';
if ($_GET['action'] === 'get_insights') {
    $ai = new AIClient($dbh);
    // Build a summary of platform data
    $bookingCount  = (int)$dbh->query("SELECT COUNT(*) FROM tblbooking WHERE status=1")->fetchColumn();
    $userCount     = (int)$dbh->query("SELECT COUNT(*) FROM tblusers")->fetchColumn();
    $packageCount  = (int)$dbh->query("SELECT COUNT(*) FROM tbltourpackages WHERE Status=1")->fetchColumn();
    $reviewCount   = (int)$dbh->query("SELECT COUNT(*) FROM tblreviews WHERE Status=1")->fetchColumn();
    $revenue       = (int)$dbh->query("SELECT COALESCE(SUM(TotalCost),0) FROM tblbooking WHERE status=1")->fetchColumn();

    $system  = "You are a travel business analytics AI. Analyse platform metrics and provide actionable business insights. Be specific and data-driven. 3-5 bullet points max.";
    $userMsg = "Platform metrics: {$bookingCount} bookings, {$userCount} users, {$packageCount} active packages, {$reviewCount} reviews, ৳{$revenue} total revenue. AI usage: {$stats['ai_calls_month']} calls this month. Sentiment: {$stats['sentiment']['positive']} positive, {$stats['sentiment']['neutral']} neutral, {$stats['sentiment']['negative']} negative reviews. Provide 3-4 concise, actionable business insights.";

    $result     = $ai->ask($system, $userMsg, 'admin_insights_' . date('Ymd'));
    $aiInsights = $result['success'] ? $result['content'] : 'AI insights temporarily unavailable.';
    echo json_encode(['insights' => $aiInsights]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Dashboard | TMS Admin</title>
<link href="../css/bootstrap.min2.css" rel="stylesheet">
<link href="../css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('../css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
body { background:#f0f4f8; }
.admin-header { background:linear-gradient(135deg,#1a1a2e,#16213e); padding:20px 30px; display:flex; align-items:center; justify-content:space-between; }
.admin-brand { color:#fff; font-size:18px; font-weight:800; display:flex; align-items:center; gap:10px; }
.admin-brand i { color:#667eea; }
.admin-nav { display:flex; gap:6px; }
.admin-nav a { color:rgba(255,255,255,.6); font-size:12px; text-decoration:none; padding:6px 14px; border-radius:7px; }
.admin-nav a:hover,.admin-nav a.active { background:rgba(255,255,255,.1); color:#fff; }

.ai-page-header { background:#fff; padding:24px 30px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; }
.ai-page-title { font-size:1.3rem; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:10px; }
.ai-page-title i { color:#667eea; }
.ai-status { display:flex; align-items:center; gap:8px; font-size:13px; color:#2F855A; font-weight:600; }
.ai-status-dot { width:8px; height:8px; background:#48bb78; border-radius:50%; animation:pulse 2s infinite; }
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

.content-wrap { padding:24px; }

/* Stat cards */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,.06); border:1px solid #f0f0f0; }
.stat-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:12px; }
.stat-value { font-size:1.6rem; font-weight:900; color:var(--text-primary); }
.stat-label { font-size:12px; color:var(--text-muted); margin-top:2px; }
.stat-trend { font-size:11px; margin-top:6px; }
.stat-trend.up { color:#2F855A; }

/* Main grid */
.main-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
.panel { background:#fff; border-radius:16px; box-shadow:0 2px 12px rgba(0,0,0,.06); border:1px solid #f0f0f0; overflow:hidden; }
.panel-header { padding:18px 20px; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; }
.panel-title { font-size:14px; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:8px; }
.panel-title i { color:#667eea; }
.panel-body { padding:20px; }

/* Sentiment bars */
.sentiment-row { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
.sentiment-label { width:70px; font-size:12px; color:#4a5568; }
.sentiment-bar { flex:1; height:10px; background:#f0f0f0; border-radius:5px; overflow:hidden; }
.sentiment-fill { height:100%; border-radius:5px; }
.sentiment-fill.positive { background:linear-gradient(90deg,#48bb78,#38a169); }
.sentiment-fill.neutral  { background:linear-gradient(90deg,#a0aec0,#718096); }
.sentiment-fill.negative { background:linear-gradient(90deg,#fc8181,#e53e3e); }
.sentiment-count { font-size:12px; font-weight:700; color:var(--text-primary); width:30px; text-align:right; }

/* Table */
.ai-table { width:100%; border-collapse:collapse; font-size:13px; }
.ai-table th { background:#f8fafc; padding:10px 12px; text-align:left; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.ai-table td { padding:10px 12px; border-bottom:1px solid #f8f8f8; color:#2d3748; }
.ai-table tr:last-child td { border-bottom:none; }
.ai-table tr:hover td { background:#f8fafc; }

/* Alert badges */
.sev-badge { font-size:11px; padding:3px 8px; border-radius:20px; font-weight:700; }
.sev-badge.info     { background:#ebf8ff; color:#2c5282; }
.sev-badge.warning  { background:#fefcbf; color:#744210; }
.sev-badge.critical { background:#fed7d7; color:#c53030; }

/* AI Insights */
.insights-panel { background:#fff; border-radius:16px; padding:24px; box-shadow:0 2px 12px rgba(0,0,0,.06); margin-bottom:20px; border:1px solid #f0f0f0; }
.insights-output { background:#f8fafc; border-radius:12px; padding:18px; font-size:13px; color:#2d3748; line-height:1.8; border-left:4px solid #667eea; margin-top:16px; white-space:pre-line; display:none; }
.btn-admin { padding:9px 18px; border-radius:9px; font-size:12px; font-weight:700; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:7px; transition:.2s; text-decoration:none; }
.btn-admin.primary { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
.btn-admin.success { background:linear-gradient(135deg,#48bb78,#38a169); color:#fff; }
.btn-admin.warning { background:linear-gradient(135deg,#ed8936,#dd6b20); color:#fff; }
.btn-admin:hover { opacity:.9; transform:translateY(-1px); }

/* Usage chart placeholder */
.usage-chart { height:160px; background:linear-gradient(135deg,#f8fafc,#edf2f7); border-radius:12px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:13px; }

/* Dark mode (this page previously had none — see the bootstrap script right
   after <head> and the toggle button added to .admin-nav). Mirrors the same
   palette admin/includes/header.php uses elsewhere so switching pages never
   feels like a different theme. */
.tms-theme-toggle-mini { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); color:#fff; width:28px; height:28px; border-radius:50%; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:13px; margin-left:8px; }
.tms-theme-toggle-mini:hover { background:rgba(255,255,255,.2); }
.hp-note{color:#4a5568;}
.cfg-num{width:80px;padding:4px 8px;border:1px solid #94a3b8;border-radius:6px;background:#fff;color:#1a202c;}
html[data-theme="dark"] .cfg-num{background:#10151c !important;color:#eef2f7 !important;border-color:#64748b !important;}
html[data-theme="dark"] .ai-table label{color:#eef2f7 !important;}
html[data-theme="dark"] .hp-note{color:#cbd5e0 !important;}
html[data-theme="dark"] body { background:#10151c !important; }
html[data-theme="dark"] .ai-page-header,
html[data-theme="dark"] .stat-card,
html[data-theme="dark"] .panel,
html[data-theme="dark"] .insights-panel { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .ai-page-title,
html[data-theme="dark"] .stat-value,
html[data-theme="dark"] .panel-title,
html[data-theme="dark"] .sentiment-count,
html[data-theme="dark"] .ai-table td { color:#eef2f7 !important; }
html[data-theme="dark"] [style*="#0B6B2E"], html[data-theme="dark"] [style*="#047857"], html[data-theme="dark"] [style*="#2F855A"], html[data-theme="dark"] .ai-status { color: #5CC79C !important; }
html[data-theme="dark"] [style*="#B91C1C"], html[data-theme="dark"] [style*="#C0392B"] { color: #FCA5A5 !important; }
html[data-theme="dark"] [style*="#0E7490"] { color: #67D5E8 !important; }
html[data-theme="dark"] [style*="#92400E"], html[data-theme="dark"] [style*="#B45309"] { color: #FBBF77 !important; }
html[data-theme="dark"] .stat-trend.up { color: #5CC79C !important; } html[data-theme="dark"] .insights-panel strong, html[data-theme="dark"] .insights-panel div { color: var(--text-primary); } html[data-theme="dark"] [style*="color:#b45309"] { color: #FBBF77 !important; }
html[data-theme="dark"] strong { color: #EAF1EC !important; }
html[data-theme="dark"] { --text-muted: #AAB8AF; --text-primary: #EAF1EC; }
html[data-theme="dark"] .stat-label,
html[data-theme="dark"] .sentiment-label { color:var(--text-muted) !important; }
html[data-theme="dark"] .panel-header { border-color:#2b3542 !important; }
html[data-theme="dark"] .ai-table th { background:#151b24 !important; color:var(--text-muted) !important; }
html[data-theme="dark"] .ai-table td { border-color:#232b37 !important; }
html[data-theme="dark"] .ai-table tr:hover td { background:#232b37 !important; }
html[data-theme="dark"] .sentiment-bar,
html[data-theme="dark"] .usage-chart { background:#151b24 !important; }
html[data-theme="dark"] .insights-output { background:#151b24 !important; color:#cdd6e0 !important; }
html[data-theme="dark"] [style*="background:#fff"], html[data-theme="dark"] [style*="background: #fff"] { background:#1b222c !important; border-color:#2b3542 !important; }
</style>
<script>
(function(){ try { document.documentElement.setAttribute('data-theme', localStorage.getItem('tms-theme') || 'light'); } catch(e) {} })();
</script>
</head>
<body>

<div class="admin-header">
  <div class="admin-brand"><i class="fa fa-magic"></i> TMS Admin — AI Dashboard</div>
  <div class="admin-nav">
    <a href="index.php">Overview</a>
    <a href="ai-dashboard.php" class="active">AI Dashboard</a>
    <a href="manage-packages.php">Packages</a>
    <a href="manage-bookings.php">Bookings</a>
    <a href="manage-users.php">Users</a>
    <a href="manage-reviews.php">Reviews</a>
    <a href="../index.php" target="_blank"><i class="fa fa-external-link"></i> View Site</a>
    <button type="button" class="tms-theme-toggle-mini" id="tmsThemeToggleMini" onclick="tmsMiniToggleTheme()" title="Toggle dark / light mode" aria-label="Toggle dark mode">
      <i class="fa fa-moon-o" id="tmsMiniIconMoon"></i>
      <i class="fa fa-sun-o" id="tmsMiniIconSun" style="display:none;"></i>
    </button>
  </div>
</div>

<div class="ai-page-header">
  <div class="ai-page-title"><i class="fa fa-magic"></i> AI System Dashboard</div>
  <?php
    // Round 34: this used to hardcode "Google Gemini" regardless of what was
    // actually configured -- now that AI_PROVIDER can select a different
    // vendor, a hardcoded label here would make the dashboard lie about
    // which one is live the moment anyone changes it.
    $activeProviderLabel = ['openai' => 'OpenAI', 'ollama' => 'Ollama (self-hosted)'][$activeProviderName] ?? 'Google Gemini';
  ?>
  <div class="ai-status">
    <span class="ai-status-dot" style="<?= $activeProviderReady ? '' : 'background:#dc2626;' ?>"></span>
    AI Engine <?= $activeProviderReady ? 'Active' : 'NOT CONFIGURED' ?> — <?= htmlspecialchars($activeProviderLabel) ?><?= $activeProviderModel ? ' (' . htmlspecialchars($activeProviderModel) . ')' : '' ?>
  </div>
</div>

<div class="content-wrap">

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><div class="panel-title"><i class="fa fa-heartbeat"></i> Provider health (last 24 hours)</div></div>
    <div class="panel-body">
      <table class="ai-table">
        <caption class="sr-only">Health, success rate and latency per AI provider</caption>
        <thead><tr><th scope="col">Provider</th><th scope="col">Role</th><th scope="col">Breaker</th><th scope="col">Calls</th><th scope="col">Success</th><th scope="col">Avg / p95 latency</th></tr></thead>
        <tbody>
        <?php foreach ($chainNames as $i => $pn): $h = $providerHealth[$pn]; $st = $providerStats[$pn] ?? null; ?>
          <tr>
            <td><?= htmlspecialchars($pn) ?></td>
            <td><?= $i === 0 ? 'Primary' : 'Fallback' ?></td>
            <td><?= htmlspecialchars($h['status']) ?><?php if ($h['status'] === 'open'): ?> (retry in <?= (int)$h['retry_after_seconds'] ?>s, <?= htmlspecialchars((string)$h['last_failure_kind']) ?>)<?php elseif ($h['status'] === 'degraded'): ?> (<?= (int)$h['consecutive_failures'] ?> recent failure(s))<?php endif; ?></td>
            <td><?= $st ? (int)$st['calls'] : 0 ?></td>
            <td><?= $st && (int)$st['calls'] > 0 ? round(100 * (int)$st['ok'] / (int)$st['calls']) . '%' : '—' ?></td>
            <td><?= $st && $st['avg_ms'] !== null ? (int)$st['avg_ms'] . ' ms / ' . ($st['p95'] !== null ? (int)$st['p95'] . ' ms' : '—') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hp-note" style="font-size:12px;margin:10px 0 0;">Fallbacks are off unless <code>AI_FALLBACK_PROVIDERS</code> is set. A cloud fallback needs <code>AI_FALLBACK_ALLOW_EXTERNAL=1</code> (conversation text would be sent to that vendor). "Open" means the provider is being skipped until the retry time.</p>
    </div>
  </div>

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><div class="panel-title"><i class="fa fa-sliders"></i> Engine controls</div></div>
    <div class="panel-body">
      <?php if ($cfgFlash !== ''): ?><div role="status" class="hp-note" style="font-size:13px;font-weight:700;margin-bottom:10px;"><?= htmlspecialchars($cfgFlash) ?></div><?php endif; ?>
      <form method="post" action="ai-dashboard.php">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="ai_cfg_action" value="save">
        <table class="ai-table">
          <caption class="sr-only">Runtime settings for the AI assistant</caption>
          <thead><tr><th scope="col">Setting</th><th scope="col">Value</th><th scope="col">Default</th></tr></thead>
          <tbody>
          <?php foreach (AIConfig::SPEC as $cn => $sp): $cv = AIConfig::get($dbh, $cn); ?>
            <tr>
              <td><label for="cfg_<?= $cn ?>"><?= htmlspecialchars($sp[4]) ?></label></td>
              <td><?php if ($sp[0] === 'bool'): ?>
                    <input type="checkbox" id="cfg_<?= $cn ?>" name="cfg[<?= $cn ?>]" value="1" <?= $cv === 1 ? 'checked' : '' ?>> <?= $cv === 1 ? 'On' : 'Off' ?>
                  <?php else: ?>
                    <input type="number" id="cfg_<?= $cn ?>" name="cfg[<?= $cn ?>]" value="<?= (int)$cv ?>" min="<?= (int)$sp[2] ?>" max="<?= (int)$sp[3] ?>" class="cfg-num">
                  <?php endif; ?></td>
              <td><?= $sp[0] === 'bool' ? ($sp[1] ? 'On' : 'Off') : (int)$sp[1] . ' (allowed ' . (int)$sp[2] . '–' . (int)$sp[3] . ')' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-primary">Save controls</button>
          <button type="submit" name="ai_cfg_action" value="reset" class="btn btn-default" onclick="return confirm('Restore every engine control to its default?');">Restore defaults</button>
        </div>
      </form>
      <p class="hp-note" style="font-size:12px;margin:10px 0 0;">Switching the assistant off makes the chat widget show its standard non-AI answers. Failover, provider keys and the cloud-fallback opt-in (<code>AI_FALLBACK_ALLOW_EXTERNAL</code>) are configured in <code>.env</code> only, and price/source verification and the safety guard cannot be switched off. Every change is logged below.</p>
      <?php $cfgLog = AIConfig::recentChanges($dbh, 8); if ($cfgLog): ?>
      <table class="ai-table" style="margin-top:12px;">
        <caption class="sr-only">Recent changes to engine controls</caption>
        <thead><tr><th scope="col">When</th><th scope="col">Setting</th><th scope="col">Change</th><th scope="col">By</th></tr></thead>
        <tbody><?php foreach ($cfgLog as $cl): ?><tr><td><?= htmlspecialchars($cl['changed_at']) ?></td><td><?= htmlspecialchars($cl['setting']) ?></td><td><?= htmlspecialchars($cl['old_value']) ?> → <?= htmlspecialchars($cl['new_value']) ?></td><td><?= htmlspecialchars($cl['changed_by']) ?></td></tr><?php endforeach; ?></tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><div class="panel-title"><i class="fa fa-database"></i> Embedding index</div></div>
    <div class="panel-body">
      <?php $embRr = EmbeddingReranker::fromEnv(15); $embCov = null; if ($embRr) { try { $embCov = EmbeddingStore::catalogueCoverage($dbh, $embRr); } catch (Throwable $e) { $embCov = null; } } ?>
      <?php if (!$embRr): ?>
        <p class="hp-note" style="margin:0;font-size:13px;">No embedding model configured (<code>AI_EMBED_MODEL</code> in <code>.env</code>). Search uses keyword (BM25) retrieval only.</p>
      <?php elseif ($embCov === null): ?>
        <p class="hp-note" style="margin:0;font-size:13px;">Index status unavailable.</p>
      <?php else: $embPct = $embCov['total'] > 0 ? (int)floor(100 * $embCov['indexed'] / $embCov['total']) : 0; ?>
        <p style="margin:0 0 8px;font-size:13px;"><?= (int)$embCov['indexed'] ?> of <?= (int)$embCov['total'] ?> packages indexed for model <code><?= htmlspecialchars($embRr->modelKey()) ?></code> (<?= $embPct ?>%<?= (int)$embCov['stale'] > 0 ? ', ' . (int)$embCov['stale'] . ' out of date' : '' ?>). Hybrid search is used only at 80% or more; below that, keyword search is used.</p>
        <form method="post" action="ai-dashboard.php"><?php tms_csrf_field(); ?><input type="hidden" name="ai_embed_action" value="sync"><button type="submit" class="btn btn-default">Index now (up to 40 packages, 20 s)</button></form>
        <p class="hp-note" style="font-size:12px;margin:10px 0 0;">Uses your configured embedding model. Run again until all packages are indexed, or use <code>php scripts/sync_embeddings.php</code> for the full catalogue.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel" style="margin-bottom:20px;">
    <div class="panel-header"><div class="panel-title"><i class="fa fa-stethoscope"></i> Assistant quality signals (last 7 days)</div></div>
    <div class="panel-body">
      <?php if ($turnStats === null || (int)$turnStats['turns'] === 0): ?>
        <p class="hp-note" style="margin:0;font-size:13px;">No assistant turns recorded yet<?= $turnStats === null ? ' (turn log unavailable)' : '' ?>. Figures appear after visitors use the chat assistant.</p>
      <?php else: $T = $turnStats; $n = max(1, (int)$T['answered']); $pc = fn($x, $d) => $d > 0 ? round(100 * $x / $d) . '%' : '—'; ?>
      <table class="ai-table">
        <caption class="sr-only">Counts per assistant turn, no message text stored</caption>
        <thead><tr><th scope="col">Measure</th><th scope="col">Value</th><th scope="col">What it means</th></tr></thead>
        <tbody>
          <tr><td>Turns</td><td><?= (int)$T['turns'] ?></td><td>Answered <?= (int)$T['answered'] ?>, blocked by the safety guard <?= (int)$T['blocked'] ?>, memory commands <?= (int)$T['memcmd'] ?>, model errors <?= (int)$T['errors'] ?></td></tr>
          <tr><td>Answers with an unverified price</td><td><?= (int)$T['unverified'] ?> (<?= $pc($T['unverified'], $n) ?>)</td><td>The model quoted a price not found in the catalogue data; a caution line was shown. Should stay near zero.</td></tr>
          <tr><td>Multi-step answers</td><td><?= (int)$T['multistep'] ?> (<?= $pc($T['multistep'], $n) ?>)</td><td>Average <?= number_format((float)$T['avg_steps'], 2) ?> tool steps per answer; stopped early by a limit: <?= (int)$T['stopped_early'] ?></td></tr>
          <tr><td>Turns with a failed tool call</td><td><?= (int)$T['tool_err'] ?></td><td>A tool was unavailable or got invalid input</td></tr>
          <tr><td>Answers showing sources</td><td><?= (int)$T['with_sources'] ?> (<?= $pc($T['with_sources'], $n) ?>)</td><td>Invented citation markers removed: <?= (int)$T['markers'] ?></td></tr>
          <tr><td>Bengali turns</td><td><?= (int)$T['bn'] ?> (<?= $pc($T['bn'], $T['turns']) ?>)</td><td>Signed-in <?= $pc($T['signed'], $T['turns']) ?>; used saved memory in <?= (int)$T['used_memory'] ?> turn(s)</td></tr>
          <tr><td>Tools used</td><td colspan="2"><?php $tl = []; foreach ($T['tools'] as $tn => $c) $tl[] = htmlspecialchars($tn) . ' ' . (int)$c; echo $tl ? implode(' &middot; ', $tl) : '—'; ?></td></tr>
        </tbody>
      </table>
      <p class="hp-note" style="font-size:12px;margin:10px 0 0;">Counts only: no message text, email or user id is stored, and rows are deleted after 90 days. These figures show how the engine behaved, not whether answers were good.</p>
      <?php $recentTurns = AITurnLog::recent($dbh, 20); if ($recentTurns): ?>
      <table class="ai-table" style="margin-top:12px;">
        <caption class="sr-only">Most recent assistant turns, counts only</caption>
        <thead><tr><th scope="col">Time</th><th scope="col">Outcome</th><th scope="col">Lang</th><th scope="col">Tools run</th><th scope="col">Flags</th></tr></thead>
        <tbody><?php foreach ($recentTurns as $rt): $fl = []; if ($rt['stopped']) $fl[] = 'stopped: ' . $rt['stopped']; if ((int)$rt['unverified_prices'] > 0) $fl[] = 'unverified price'; if ((int)$rt['removed_markers'] > 0) $fl[] = 'marker removed'; ?>
          <tr><td><?= htmlspecialchars($rt['created_at']) ?></td><td><?= htmlspecialchars($rt['outcome']) ?></td><td><?= htmlspecialchars($rt['language']) ?></td><td><?= htmlspecialchars($rt['tools'] !== '' ? $rt['tools'] : '—') ?></td><td><?= htmlspecialchars($fl ? implode(', ', $fl) : '—') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($batchMsg): ?>
  <div style="background:#c6f6d5;border:1px solid #9ae6b4;color:#276749;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;">
    <i class="fa fa-check-circle"></i> <?= $batchMsg ?>
  </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon" style="background:#ebf8ff;">🤖</div>
      <div class="stat-value"><?= number_format($stats['ai_calls_today']) ?></div>
      <div class="stat-label">AI Calls Today</div>
      <div class="stat-trend up"><i class="fa fa-arrow-up"></i> <?= number_format($stats['ai_calls_month']) ?> this month</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0fff4;">🗺️</div>
      <div class="stat-value"><?= number_format($stats['itineraries']) ?></div>
      <div class="stat-label">Itineraries Generated</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#faf5ff;">✈️</div>
      <div class="stat-value"><?= number_format($stats['copilot_msgs']) ?></div>
      <div class="stat-label">Copilot Messages</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#fffbeb;">📖</div>
      <div class="stat-value"><?= number_format($stats['memories']) ?></div>
      <div class="stat-label">Travel Memories</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff5f5;">⭐</div>
      <div class="stat-value"><?= $stats['unanalysed_reviews'] ?></div>
      <div class="stat-label">Unanalysed Reviews</div>
      <?php if ($stats['unanalysed_reviews'] > 0): ?>
      <a href="?action=analyse_reviews" class="btn-admin warning" style="margin-top:8px;padding:5px 10px;font-size:11px;">Analyse Now</a>
      <?php endif; ?>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#f0f9ff;">📡</div>
      <div class="stat-value"><?= $telemetrySuccessRate !== null ? $telemetrySuccessRate . '%' : '—' ?></div>
      <div class="stat-label">AI Success Rate (7d, live calls)</div>
      <div class="stat-trend" style="color:var(--text-muted);"><?= $telemetryAvgLatency !== null ? number_format($telemetryAvgLatency) . ' ms avg latency' : 'No calls logged yet' ?></div>
    </div>
  </div>

  <!-- AI Insights -->
  <div class="insights-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;">
      <div style="font-size:15px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;"><i class="fa fa-lightbulb-o" style="color:#667eea;"></i> AI Business Insights</div>
      <button class="btn-admin primary" onclick="getInsights()" id="insightsBtn"><i class="fa fa-magic"></i> Generate Insights</button>
    </div>
    <div class="insights-output" id="insightsOutput"></div>
  </div>

  <!-- AI Evaluation Suite (Round 35, item 22) -->
  <div class="insights-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div style="font-size:15px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;"><i class="fa fa-flask" style="color:#667eea;"></i> AI Evaluation Suite</div>
      <button class="btn-admin primary" onclick="runEvalSuite()" id="evalSuiteBtn"><i class="fa fa-play"></i> Run Eval Suite</button>
    </div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">
      Runs 9 real calls (recommender, itinerary, sentiment, budget optimizer, packing list, crowd predictor, package generator, site assistant, NL search parser) against the live <?= htmlspecialchars($activeProviderLabel) ?> provider and checks whether each one genuinely succeeded -- not just that a graceful fallback covered for it. Uses real API quota and can take 1-3 minutes (paced with short gaps between calls to avoid tripping a free-tier provider's own per-minute limit); run manually when checking a provider/prompt change, not on a schedule.
    </div>
    <div class="insights-output" id="evalSuiteOutput" style="display:none;white-space:pre-wrap;"></div>
  </div>

  <!-- Main grid -->
  <div class="main-grid">

    <!-- Sentiment -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-smile-o"></i> Review Sentiment Analysis</div>
        <a href="?action=analyse_reviews" class="btn-admin success" style="font-size:11px;padding:5px 10px;">Run Analysis</a>
      </div>
      <div class="panel-body">
        <?php
        $totalSent = array_sum($stats['sentiment']);
        foreach (['positive'=>'Positive','neutral'=>'Neutral','negative'=>'Negative'] as $key => $label):
          $cnt = $stats['sentiment'][$key];
          $pct = $totalSent > 0 ? round($cnt / $totalSent * 100) : 0;
        ?>
        <div class="sentiment-row">
          <span class="sentiment-label"><?= $label ?></span>
          <div class="sentiment-bar"><div class="sentiment-fill <?= $key ?>" style="width:<?= $pct ?>%"></div></div>
          <span class="sentiment-count"><?= $cnt ?></span>
        </div>
        <?php endforeach; ?>
        <div style="font-size:12px;color:var(--text-muted);margin-top:12px;">Total analysed: <?= $totalSent ?> reviews</div>
      </div>
    </div>

    <!-- Business Forecasts — reviewer ask: predicted revenue, packages at
         risk of low occupancy, customers likely to cancel/return. Simple,
         transparent heuristics over real booking data (see the
         tms_forecast_revenue()/tms_packages_at_risk()/etc. functions above
         for exactly what each one measures) — not an opaque ML score. -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-line-chart"></i> Business Forecasts</div>
      </div>
      <div class="panel-body">
        <?php if ($revenueForecast['available']): ?>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #eef1f5;">
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;">This Month So Far</div>
            <div style="font-size:20px;font-weight:800;color:var(--text-primary);">৳<?= number_format($revenueForecast['so_far_this_month']) ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;">Projected Month Total</div>
            <div style="font-size:20px;font-weight:800;color:#0E7490;">৳<?= number_format($revenueForecast['projected_total_this_month']) ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;">Recent Monthly Avg</div>
            <div style="font-size:20px;font-weight:800;color:var(--text-muted);">৳<?= number_format($revenueForecast['avg_monthly']) ?></div>
          </div>
        </div>
        <div style="font-size:11px;color:#c2c9d1;margin-top:-10px;margin-bottom:18px;">Trend-based estimate from the last 3 months of confirmed bookings — not a guarantee.</div>
        <?php else: ?>
        <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:18px;">Not enough booking history yet to project revenue (needs at least 2 full months of confirmed bookings).</div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;">
          <div>
            <div style="font-size:11.5px;font-weight:700;color:#0E7490;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="fa fa-shopping-cart"></i> Likely to Book Soon</div>
            <?php if (empty($likelyNextBookings)): ?>
              <div style="font-size:12px;color:var(--text-muted);">No packages sitting in a cart right now that haven't already been booked.</div>
            <?php else: foreach ($likelyNextBookings as $lb): ?>
              <div style="font-size:12.5px;padding:7px 0;border-bottom:1px solid #f8fafc;">
                <strong><?= htmlspecialchars($lb->UserEmail) ?></strong> &middot; <?= htmlspecialchars($lb->PackageName) ?>
                <div style="color:var(--text-muted);font-size:11px;">Added to cart <?= htmlspecialchars($lb->CreatedAt) ?> &middot; est. ৳<?= number_format($lb->EstValue) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div>
            <div style="font-size:11.5px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="fa fa-exclamation-triangle"></i> Packages at Risk</div>
            <?php if (empty($packagesAtRisk)): ?>
              <div style="font-size:12px;color:var(--text-muted);">No previously-popular packages have gone quiet — nothing at risk right now.</div>
            <?php else: foreach ($packagesAtRisk as $p): ?>
              <div style="font-size:12.5px;padding:7px 0;border-bottom:1px solid #f8fafc;">
                <strong><?= htmlspecialchars($p->PackageName) ?></strong>
                <div style="color:var(--text-muted);font-size:11px;">No bookings in 30+ days — last was <?= htmlspecialchars($p->last_booking_date) ?> (<?= (int)$p->lifetime_bookings ?> lifetime bookings)</div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div>
            <div style="font-size:11.5px;font-weight:700;color:#B91C1C;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="fa fa-user-times"></i> Cancellation Risk</div>
            <?php if (empty($customersCancelRisk)): ?>
              <div style="font-size:12px;color:var(--text-muted);">No customers with a high cancellation pattern right now.</div>
            <?php else: foreach ($customersCancelRisk as $c): ?>
              <div style="font-size:12.5px;padding:7px 0;border-bottom:1px solid #f8fafc;">
                <strong><?= htmlspecialchars($c->UserEmail) ?></strong>
                <div style="color:var(--text-muted);font-size:11px;"><?= (int)$c->cancel_rate ?>% cancel rate (<?= (int)$c->cancelled ?> of <?= (int)$c->total ?> bookings)</div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div>
            <div style="font-size:11.5px;font-weight:700;color:#0B6B2E;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><i class="fa fa-user-plus"></i> Likely to Return</div>
            <?php if (empty($customersReturning)): ?>
              <div style="font-size:12px;color:var(--text-muted);">No repeat-customer pattern detected yet.</div>
            <?php else: foreach ($customersReturning as $c): ?>
              <div style="font-size:12.5px;padding:7px 0;border-bottom:1px solid #f8fafc;">
                <strong><?= htmlspecialchars($c->UserEmail) ?></strong>
                <div style="color:var(--text-muted);font-size:11px;"><?= (int)$c->confirmed_bookings ?> confirmed bookings, last on <?= htmlspecialchars($c->last_booking) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Top Issues & Highlights (Phase 2c) -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-list-ul"></i> Most Mentioned in Reviews</div>
      </div>
      <div class="panel-body">
        <div style="font-size:11.5px;font-weight:700;color:#B91C1C;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">Top Issues</div>
        <?php if (empty($topPhrases['issues'])): ?>
          <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:16px;">No recurring issues detected yet.</div>
        <?php else: foreach ($topPhrases['issues'] as $p): ?>
          <div class="sentiment-row" style="margin-bottom:6px;">
            <span style="flex:1;font-size:12.5px;color:#4a5568;"><?= htmlspecialchars($p['phrase']) ?></span>
            <span class="sentiment-count" style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 8px;width:auto;"><?= $p['count'] ?></span>
          </div>
        <?php endforeach; endif; ?>

        <div style="font-size:11.5px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:.4px;margin:16px 0 8px;">Top Highlights</div>
        <?php if (empty($topPhrases['highlights'])): ?>
          <div style="font-size:12.5px;color:var(--text-muted);">No recurring highlights detected yet.</div>
        <?php else: foreach ($topPhrases['highlights'] as $p): ?>
          <div class="sentiment-row" style="margin-bottom:6px;">
            <span style="flex:1;font-size:12.5px;color:#4a5568;"><?= htmlspecialchars($p['phrase']) ?></span>
            <span class="sentiment-count" style="background:#d1fae5;color:#065f46;border-radius:10px;padding:1px 8px;width:auto;"><?= $p['count'] ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Flagged Reviews / Fraud Detection (Phase 2c) -->
    <div class="panel" style="grid-column: 1 / -1;">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-shield" style="color:#B91C1C;"></i> Flagged Reviews (Possible Fake/Spam)</div>
        <span style="font-size:11px;color:var(--text-muted);">Heuristic soft-flags — always verify before removing a review</span>
      </div>
      <div class="panel-body">
        <?php if (empty($suspiciousReviews)): ?>
          <div style="font-size:12.5px;color:var(--text-muted);">No reviews currently flagged.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="tms-table" style="font-size:12.5px;">
          <thead><tr><th>Package</th><th>User</th><th>Rating</th><th>Review</th><th>Why Flagged</th><th>Posted</th></tr></thead>
          <tbody>
          <?php foreach ($suspiciousReviews as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r->PackageName ?? '—') ?></td>
            <td><?= htmlspecialchars($r->UserName ?? $r->UserEmail ?? '—') ?></td>
            <td><?= (int)$r->Rating ?>/5</td>
            <td style="max-width:280px;"><?= htmlspecialchars(mb_strimwidth($r->ReviewText ?? '', 0, 120, '…')) ?></td>
            <td style="color:#991b1b;"><?= htmlspecialchars($r->suspicious_reason ?? '') ?></td>
            <td><?= htmlspecialchars($r->PostingDate ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Duplicate Accounts (Phase 2f) -->
    <div class="panel" style="grid-column: 1 / -1;">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-users" style="color:#d97706;"></i> Possible Duplicate Accounts</div>
        <span style="font-size:11px;color:var(--text-muted);">Shared phone number or Gmail dot/plus-address variants</span>
      </div>
      <div class="panel-body">
        <?php if (empty($duplicateAccounts)): ?>
          <div style="font-size:12.5px;color:var(--text-muted);">No duplicate account patterns detected.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="tms-table" style="font-size:12.5px;">
          <thead><tr><th>Reason</th><th>Accounts</th></tr></thead>
          <tbody>
          <?php foreach ($duplicateAccounts as $g): ?>
          <tr>
            <td><?= htmlspecialchars($g['reason']) ?></td>
            <td><?= htmlspecialchars(implode(', ', $g['accounts'])) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Suspicious Bookings (Phase 2f) -->
    <div class="panel" style="grid-column: 1 / -1;">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-exclamation-triangle" style="color:#d97706;"></i> Suspicious Booking Patterns</div>
        <span style="font-size:11px;color:var(--text-muted);">Coupon abuse clusters, rapid-fire bookings, abnormal cancellation rates</span>
      </div>
      <div class="panel-body">
        <?php if (empty($suspiciousBookings)): ?>
          <div style="font-size:12.5px;color:var(--text-muted);">No suspicious booking patterns detected.</div>
        <?php else: ?>
        <div class="table-responsive"><table class="tms-table" style="font-size:12.5px;">
          <thead><tr><th>Type</th><th>Details</th></tr></thead>
          <tbody>
          <?php foreach ($suspiciousBookings as $b):
            $typeLabel = ['coupon_cluster'=>'Coupon Abuse','rapid_fire'=>'Rapid-Fire Bookings','high_cancellation'=>'High Cancellation Rate'][$b['type']] ?? $b['type'];
          ?>
          <tr>
            <td><span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:700;"><?= htmlspecialchars($typeLabel) ?></span></td>
            <td><?= htmlspecialchars($b['reason']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top AI Users -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-users"></i> Top AI Users (7 days)</div>
      </div>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive"><table class="ai-table">
          <thead><tr><th>User</th><th>AI Calls</th></tr></thead>
          <tbody>
            <?php foreach ($topUsers as $u): ?>
            <tr>
              <td><?= htmlspecialchars(substr($u->user_email, 0, 28)) ?></td>
              <td><strong><?= $u->calls ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($topUsers)): ?>
            <tr><td colspan="2" style="text-align:center;color:var(--text-muted);padding:20px;">No data yet</td></tr>
            <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- AI Telemetry by Feature (Round 34, item 21) -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-tachometer"></i> AI Telemetry by Feature (7 days)</div>
      </div>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive"><table class="ai-table">
          <thead><tr><th>Feature</th><th>Calls</th><th>Success</th><th>Avg Latency</th><th>Tokens</th></tr></thead>
          <tbody>
            <?php foreach ($telemetryByFeature as $t):
                $rate = (int)$t->calls > 0 ? round(100 * (int)$t->successes / (int)$t->calls) : 0;
            ?>
            <tr>
              <td><?= htmlspecialchars($t->feature) ?></td>
              <td><strong><?= (int)$t->calls ?></strong></td>
              <td style="color:<?= $rate >= 90 ? '#0B6B2E' : ($rate >= 60 ? '#92400E' : '#B91C1C') ?>;"><?= $rate ?>%</td>
              <td style="font-size:11px;color:var(--text-muted);"><?= $t->avg_latency_ms !== null ? number_format((int)$t->avg_latency_ms) . ' ms' : '—' ?></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= $t->total_tokens > 0 ? number_format((int)$t->total_tokens) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($telemetryByFeature)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px;">No telemetry logged yet -- generates as AI features are used</td></tr>
            <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- Recent Eval Suite Runs (Round 35, item 22) -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-flask"></i> Recent Eval Suite Runs</div>
      </div>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive"><table class="ai-table">
          <thead><tr><th>Date</th><th>Provider</th><th>Result</th><th>Run By</th></tr></thead>
          <tbody>
            <?php foreach ($recentEvalRuns as $run):
                $runRate = (int)$run->total_cases > 0 ? round(100 * (int)$run->passed_cases / (int)$run->total_cases) : 0;
            ?>
            <tr>
              <td style="font-size:11px;color:var(--text-muted);"><?= date('M j H:i', strtotime($run->created_at)) ?></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($run->provider . ' (' . $run->model . ')') ?></td>
              <td style="color:<?= $runRate >= 90 ? '#0B6B2E' : ($runRate >= 60 ? '#92400E' : '#B91C1C') ?>;"><strong><?= (int)$run->passed_cases ?>/<?= (int)$run->total_cases ?></strong></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars(substr($run->run_by, 0, 20)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentEvalRuns)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px;">No eval runs yet -- use the "Run Eval Suite" button above</td></tr>
            <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- Recent Itineraries -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-calendar"></i> Recent AI Itineraries</div>
      </div>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive"><table class="ai-table">
          <thead><tr><th>User</th><th>Model</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach ($recentItineraries as $it): ?>
            <tr>
              <td><?= htmlspecialchars(substr($it->user_email, 0, 22)) ?></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($it->ai_version) ?></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= date('M j H:i', strtotime($it->created_at)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentItineraries)): ?>
            <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:20px;">No itineraries yet</td></tr>
            <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

    <!-- Chatbot Quick-Reply Analytics -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-comments"></i> Chatbot Quick-Replies (30 days)</div>
      </div>
      <div class="panel-body">
        <?php if (empty($topQuickReplies)): ?>
        <div style="text-align:center;color:var(--text-muted);padding:20px;">No quick-reply clicks recorded yet</div>
        <?php else: foreach ($topQuickReplies as $qr): $pct = $maxQrClicks > 0 ? round(($qr->clicks / $maxQrClicks) * 100) : 0; ?>
        <div style="margin-bottom:10px;">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;color:#334155;margin-bottom:3px;">
            <span><?= htmlspecialchars($qr->Label) ?></span>
            <strong><?= (int)$qr->clicks ?></strong>
          </div>
          <div style="background:#eef1f6;border-radius:20px;height:7px;overflow:hidden;">
            <div style="background:#667eea;height:100%;width:<?= $pct ?>%;border-radius:20px;"></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Safety Alerts -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><i class="fa fa-shield"></i> Safety Alerts</div>
        <button class="btn-admin primary" style="font-size:11px;padding:5px 10px;" onclick="addAlert()">+ Add Alert</button>
      </div>
      <?php if ($alertFlashMsg): ?>
      <div style="padding:10px 16px;background:#EEF7F1;color:#104B3A;font-size:12.5px;border-bottom:1px solid #DCF1E5;"><i class="fa fa-info-circle"></i> <?= $alertFlashMsg ?></div>
      <?php endif; ?>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive"><table class="ai-table">
          <thead><tr><th>Destination</th><th>Type</th><th>Severity</th><th>Message</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($safetyAlerts as $alert): ?>
            <tr>
              <td><?= htmlspecialchars($alert->destination) ?></td>
              <td><?= htmlspecialchars($alert->alert_type) ?></td>
              <td><span class="sev-badge <?= $alert->severity ?>"><?= $alert->severity ?></span></td>
              <td style="max-width:260px;font-size:12px;color:#4a5568;"><?= htmlspecialchars($alert->message) ?></td>
              <td>
                <form method="post" style="display:inline;" onsubmit="return confirm('Dismiss this alert? Travelers will stop seeing it.');">
                  <?php tms_csrf_field(); ?>
                  <input type="hidden" name="dismiss_alert" value="<?= (int)$alert->id ?>">
                  <button type="submit" class="btn-admin" style="font-size:11px;padding:3px 8px;background:#fee2e2;color:#991b1b;"><i class="fa fa-times"></i> Dismiss</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($safetyAlerts)): ?>
            <tr><td colspan="5" style="text-align:center;color:#2F855A;padding:20px;"><i class="fa fa-check-circle"></i> No active alerts</td></tr>
            <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>

  </div>

  <!-- Quick actions -->
  <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.06);border:1px solid #f0f0f0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
    <span style="font-size:14px;font-weight:800;color:var(--text-primary);margin-right:8px;"><i class="fa fa-bolt" style="color:#667eea;"></i> Quick Actions:</span>
    <a href="?action=analyse_reviews" class="btn-admin success"><i class="fa fa-search"></i> Analyse Reviews</a>
    <a href="manage-packages.php" class="btn-admin primary"><i class="fa fa-box"></i> Manage Packages</a>
    <a href="manage-bookings.php" class="btn-admin primary"><i class="fa fa-calendar"></i> View Bookings</a>
    <a href="manage-users.php" class="btn-admin warning"><i class="fa fa-users"></i> Manage Users</a>
    <a href="../ai-recommend.php" target="_blank" class="btn-admin" style="background:#e2e8f0;color:#4a5568;"><i class="fa fa-external-link"></i> Test AI Recommender</a>
  </div>

</div>

<script>
const TMS_CSRF_TOKEN = <?= json_encode(tms_csrf_token()) ?>;
async function getInsights() {
  const btn    = document.getElementById('insightsBtn');
  const output = document.getElementById('insightsOutput');
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating…';
  btn.disabled  = true;
  output.style.display = 'block';
  output.textContent   = 'AI is analysing your platform data…';

  try {
    const res  = await fetch('ai-dashboard.php?action=get_insights');
    const json = await res.json();
    output.textContent = json.insights || 'No insights generated.';
  } catch(e) {
    output.textContent = 'Failed to load AI insights. Please try again.';
  }
  btn.innerHTML = '<i class="fa fa-magic"></i> Generate Insights';
  btn.disabled  = false;
}

async function runEvalSuite() {
  const btn    = document.getElementById('evalSuiteBtn');
  const output = document.getElementById('evalSuiteOutput');
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running (up to ~3 min)…';
  btn.disabled  = true;
  output.style.display = 'block';
  output.textContent   = 'Running 13 real AI calls against the live provider -- this can take a few minutes, especially the itinerary and weather-accuracy cases…';

  try {
    const res = await fetch('', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `run_eval_suite=1&csrf_token=${encodeURIComponent(TMS_CSRF_TOKEN)}`
    });
    const json = await res.json();
    const lines = [`${json.passed}/${json.total} cases passed — provider: ${json.provider} (${json.model})`, ''];
    (json.cases || []).forEach(c => {
      const mark = c.passed ? '✅' : '❌';
      const cachedNote = (c.telemetry && Number(c.telemetry.cached) === 1) ? ' [cached]' : '';
      const sanityNote = c.passed && !c.sanity_ok ? ' (⚠ succeeded but response shape looked off)' : '';
      // Round 95: a content-accuracy case (package_accuracy,
      // factual_accuracy_policy, refusal_offtopic) can fail with no
      // run_error and a telemetry row that itself shows success=1 -- the
      // AI call worked fine, the CONTENT was wrong/hallucinated/off-script.
      // Without this branch that showed as a confusing blank or
      // "no telemetry recorded" note despite telemetry existing.
      let errNote = '';
      if (!c.passed) {
        if (c.run_error) errNote = ' — ' + c.run_error;
        else if (c.telemetry && c.telemetry.error_message) errNote = ' — ' + c.telemetry.error_message;
        else if (!c.sanity_ok) errNote = ' — response failed this case\'s content/accuracy check';
        else errNote = ' — no telemetry recorded for this call';
      }
      lines.push(`${mark} ${c.label}${cachedNote} (${c.wall_ms} ms)${sanityNote}${errNote}`);
    });
    output.textContent = lines.join('\n');
  } catch (e) {
    output.textContent = 'Eval suite failed to run. Please try again.';
  }
  btn.innerHTML = '<i class="fa fa-play"></i> Run Eval Suite';
  btn.disabled  = false;
}

function addAlert() {
  const dest = prompt('Destination:');
  if (!dest) return;
  const type = prompt('Alert type (weather/safety/road):');
  if (!type) return;
  const msg  = prompt('Alert message:');
  if (!msg) return;
  // Round 20 fix (item 17 -- Travel Alert System): this used to POST to
  // nowhere -- there was no server-side handler for add_alert at all, so
  // the button silently did nothing. Now posts with the page's own CSRF
  // token to the real handler at the top of this file.
  fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: `add_alert=1&csrf_token=${encodeURIComponent(TMS_CSRF_TOKEN)}&destination=${encodeURIComponent(dest)}&alert_type=${encodeURIComponent(type)}&message=${encodeURIComponent(msg)}&severity=warning`
  }).then(() => location.reload());
}

function tmsMiniToggleTheme() {
  var html = document.documentElement;
  var isDark = html.getAttribute('data-theme') === 'dark';
  var next = isDark ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  try { localStorage.setItem('tms-theme', next); } catch(e) {}
  tmsMiniSyncIcon();
}
function tmsMiniSyncIcon() {
  var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  var moon = document.getElementById('tmsMiniIconMoon');
  var sun = document.getElementById('tmsMiniIconSun');
  if (moon && sun) { moon.style.display = isDark ? 'none' : ''; sun.style.display = isDark ? '' : 'none'; }
}
tmsMiniSyncIcon();
</script>
</body>
</html>
