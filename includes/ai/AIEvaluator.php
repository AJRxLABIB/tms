<?php
/**
 * AIEvaluator.php — Round 35 (item 22: "AI Evaluation suite")
 *
 * An on-demand (admin-triggered, never automatic) harness that exercises a
 * representative slice of TMS's real AI features end-to-end against
 * whichever provider AI_PROVIDER currently selects (Round 34), and reports
 * whether each one produced a genuine successful AI-backed answer -- not
 * just "did some response-shaped array come back", because several of
 * these features gracefully degrade to a deterministic non-AI fallback on
 * failure BY DESIGN (that's a feature, not a bug), which means checking the
 * return value's shape alone can't tell a working AI integration from a
 * silently-broken one serving fallback text. Instead, each case reads back
 * the tbl_ai_telemetry row Round 34 already writes for that exact call
 * (matched by feature tag + a dedicated eval identity + telemetry row id
 * watermark) to get a ground-truth success/cached/latency/token signal.
 *
 * Every eval call runs against real feature classes with realistic but
 * clearly-synthetic input, under a single fixed identity
 * (ai-eval@tms-internal.local) -- never a real customer's data (Rule 3).
 * Side-effect rows this identity leaves in per-feature tables (saved
 * itineraries, generated packages, one sentiment row under a sentinel
 * review id far outside any real review's range) are deleted again after
 * each run so they never appear in real admin analytics (top users,
 * itinerary counts, sentiment breakdown, etc.) -- the one exception is
 * tbl_ai_telemetry itself and this class's own tbl_ai_eval_runs history,
 * which are the whole point of running this.
 *
 * Deliberately NOT covered here (documented, not silently skipped):
 * - TripCopilot::chat() needs a real booking to build trip context; adding
 *   dedicated fixture-booking machinery just for this eval suite felt like
 *   more infrastructure than item 22 asked for (Rule 73).
 * - ExpenseAnalyst::generateInsight() needs existing expense rows for a
 *   user, same reasoning.
 * - The AI Image Analyzer (askWithImage) needs real binary image input,
 *   which this text-only harness doesn't supply.
 * A future round could add a small persistent eval-fixture booking to
 * close the first two; this one covers the AI call sites that don't
 * need one (9, as of Round 36's NLSearchParser).
 */

require_once __DIR__ . '/TravelRecommender.php';
require_once __DIR__ . '/SentimentAnalyser.php';
require_once __DIR__ . '/ItineraryPlanner.php';
require_once __DIR__ . '/BudgetOptimizer.php';
require_once __DIR__ . '/CrowdPredictor.php';
require_once __DIR__ . '/PackageGenerator.php';
require_once __DIR__ . '/SiteAssistant.php';
require_once __DIR__ . '/NLSearchParser.php';

class AIEvaluator {

    private const EVAL_EMAIL     = 'ai-eval@tms-internal.local';
    private const EVAL_REVIEW_ID = 999999999; // far outside any real review's auto-increment range

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Runs every eval case, saves a summary row to tbl_ai_eval_runs, and
     * returns the full per-case results for immediate display.
     *
     * @return array{cases: array<int, array>, passed: int, total: int, provider: string, model: string}
     */
    public function runAll(): array {
        $cases = $this->cases();
        $results = [];

        foreach ($cases as $i => $case) {
            // A small gap between cases -- found necessary the first time
            // this suite actually ran: 8 back-to-back calls on a free-tier
            // key tripped Gemini's own per-minute burst limit and every
            // case came back "busy right now" (correctly classified as
            // retriable by GeminiProvider, but it defeats the eval's
            // purpose if it routinely rate-limits itself). Skipped before
            // the first case since nothing has run yet.
            if ($i > 0) usleep(3_000_000);
            $results[] = $this->runCase($case);
        }

        $this->cleanupSideEffects();

        $passed   = count(array_filter($results, fn($r) => $r['passed']));
        $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini';
        $model    = $provider === 'openai' ? (defined('OPENAI_MODEL') ? OPENAI_MODEL : '') : (defined('GEMINI_MODEL') ? GEMINI_MODEL : '');

        $summary = ['cases' => $results, 'passed' => $passed, 'total' => count($results), 'provider' => $provider, 'model' => $model];
        $this->saveRun($summary);

        return $summary;
    }

    /**
     * The eval cases. Each is a realistic, clearly-synthetic call into a
     * real AI feature class -- same code path a real visitor would hit,
     * just with fixture input under the dedicated eval identity.
     */
    private function cases(): array {
        return [
            [
                'key'   => 'recommender',
                'label' => 'Travel Recommender',
                'run'   => function () {
                    $tr = new TravelRecommender($this->db);
                    return $tr->recommend([
                        'budget' => 80000 + random_int(1, 999), // varies the cache key each run
                        'travelers' => 2, 'travel_type' => 'adventure', 'climate' => 'tropical', 'duration' => 5,
                    ], self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && !empty($out),
            ],
            [
                'key'   => 'sentiment',
                'label' => 'Sentiment Analyser',
                'run'   => function () {
                    $sa = new SentimentAnalyser($this->db);
                    return $sa->analyse(self::EVAL_REVIEW_ID, 'The hotel was clean and the guide was fantastic, but the transfer bus was late twice.', 4);
                },
                'sanity' => fn($out) => is_array($out) && in_array($out['sentiment'] ?? '', ['positive', 'neutral', 'negative'], true),
                // SentimentAnalyser::analyse() doesn't take an $email argument
                // at all -- it hardcodes 'system' as the AIClient userEmail
                // (sentiment analysis runs as a background/system job, not
                // attributed to any one visitor's rate limit), so its
                // telemetry rows land under 'system', not the eval identity.
                // Found empirically the first time this suite actually ran --
                // every other case DOES pass its own $email through.
                'telemetry_email' => 'system',
            ],
            [
                'key'   => 'itinerary',
                'label' => 'Itinerary Planner',
                'run'   => function () {
                    $ip = new ItineraryPlanner($this->db);
                    return $ip->generate([
                        'destination' => "Cox's Bazar, Bangladesh",
                        'duration'    => random_int(3, 5), // varies the cache key each run
                        'start_date'  => date('Y-m-d', strtotime('+21 days')),
                        'travelers'   => 2, 'budget' => 60000, 'travel_type' => 'leisure',
                    ], self::EVAL_EMAIL, null);
                },
                'sanity' => fn($out) => is_array($out) && !empty($out['days']),
            ],
            [
                'key'   => 'budget_optimize',
                'label' => 'Budget Optimizer',
                'run'   => function () {
                    $bo = new BudgetOptimizer($this->db);
                    return $bo->optimizeBudget([
                        'accommodation' => 20000, 'food' => 10000, 'transport' => 8000, 'activities' => 7000,
                    ], "Cox's Bazar", self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && isset($out['overall_assessment']),
            ],
            [
                'key'   => 'budget_packing',
                'label' => 'Packing List Generator',
                'run'   => function () {
                    $bo = new BudgetOptimizer($this->db);
                    return $bo->generatePackingList([
                        'destination' => "Cox's Bazar", 'duration' => random_int(3, 5), // varies cache key
                        'travel_type' => 'leisure', 'climate' => 'tropical',
                    ], self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && !empty($out['essentials']),
            ],
            [
                'key'   => 'crowd',
                'label' => 'Crowd Predictor',
                'run'   => function () {
                    $cp = new CrowdPredictor($this->db);
                    return $cp->predictBestVisitingTime("Cox's Bazar", self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && !empty($out['ai_narrative']),
            ],
            [
                'key'   => 'package_gen',
                'label' => 'AI Package Generator',
                'run'   => function () {
                    $pg = new PackageGenerator($this->db);
                    return $pg->generate([
                        'budget' => 70000 + random_int(1, 999), 'travel_type' => 'honeymoon', // varies cache key
                        'duration' => 4, 'climate' => 'tropical',
                    ], self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && !empty($out),
            ],
            [
                'key'   => 'site_assistant',
                'label' => 'Site Assistant Chatbot',
                'run'   => function () {
                    $sas = new SiteAssistant($this->db);
                    return $sas->reply('What destinations do you recommend for a 3-day trip under 30000 taka?', [], self::EVAL_EMAIL, 'home');
                },
                'sanity' => fn($out) => is_array($out) && !empty($out['reply'] ?? ($out['content'] ?? '')),
            ],
            [
                // Round 36 (item 30) -- added when NLSearchParser shipped, so
                // the eval suite keeps covering every real AI call site
                // rather than drifting stale as new features are added.
                'key'   => 'nl_search',
                'label' => 'NL Search Parser',
                'run'   => function () {
                    $nl = new NLSearchParser($this->db);
                    return $nl->parse('cheap 3-day beach trip under 20000 taka in December', self::EVAL_EMAIL);
                },
                'sanity' => fn($out) => is_array($out) && isset($out['filters']) && is_array($out['filters']),
            ],
        ];
    }

    private function runCase(array $case): array {
        $beforeId = (int)($this->db->query("SELECT COALESCE(MAX(id),0) FROM tbl_ai_telemetry")->fetchColumn());
        $startedAt = microtime(true);

        $output   = null;
        $runError = null;
        try {
            $output = ($case['run'])();
        } catch (Throwable $e) {
            $runError = $e->getMessage();
        }

        $wallMs = (int)round((microtime(true) - $startedAt) * 1000);

        // Ground truth: the telemetry row Round 34's AIClient wrote for
        // THIS call (feature tag + dedicated eval identity + id watermark
        // rules out picking up an unrelated concurrent call).
        $stmt = $this->db->prepare(
            "SELECT provider, model, success, cached, latency_ms, prompt_tokens, completion_tokens, total_tokens, error_message
             FROM tbl_ai_telemetry
             WHERE id > ? AND feature = ? AND user_email = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$beforeId, $case['key'], $case['telemetry_email'] ?? self::EVAL_EMAIL]);
        $telemetry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $sanityOk = ($runError === null && $output !== null) ? (bool)($case['sanity'])($output) : false;
        $aiSuccess = $telemetry !== null && (int)$telemetry['success'] === 1;

        return [
            'key'       => $case['key'],
            'label'     => $case['label'],
            'passed'    => $runError === null && $aiSuccess,
            'sanity_ok' => $sanityOk,
            'wall_ms'   => $wallMs,
            'run_error' => $runError,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * Deletes this eval identity's rows from the per-feature tables that
     * don't already have their own retention/uniqueness handling, so a
     * manual eval run never shows up in real admin analytics (top AI
     * users, itinerary counts, sentiment breakdown, saved AI packages).
     * tbl_ai_telemetry and tbl_ai_usage_log are deliberately left alone --
     * an eval call is a real call and belongs in real usage/telemetry
     * history, which is the whole reason this suite reads telemetry back
     * instead of trusting each feature's own return value.
     */
    private function cleanupSideEffects(): void {
        try { $this->db->prepare("DELETE FROM tbl_review_sentiment WHERE review_id = ?")->execute([self::EVAL_REVIEW_ID]); } catch (Exception $e) {}
        try { $this->db->prepare("DELETE FROM tbl_ai_itineraries WHERE user_email = ?")->execute([self::EVAL_EMAIL]); } catch (Exception $e) {}
        try { $this->db->prepare("DELETE FROM tbl_ai_packages WHERE user_email = ?")->execute([self::EVAL_EMAIL]); } catch (Exception $e) {}
    }

    private function saveRun(array $summary): void {
        try {
            $this->db->prepare(
                "INSERT INTO tbl_ai_eval_runs (provider, model, total_cases, passed_cases, results_json, run_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $summary['provider'],
                $summary['model'],
                $summary['total'],
                $summary['passed'],
                json_encode($summary['cases'], JSON_UNESCAPED_UNICODE),
                $_SESSION['alogin'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            error_log('[TMS AI Eval] Failed to save eval run: ' . $e->getMessage());
        }
    }

    /**
     * Recent eval run history for the admin dashboard.
     */
    public function recentRuns(int $limit = 10): array {
        try {
            $stmt = $this->db->prepare("SELECT id, provider, model, total_cases, passed_cases, run_by, created_at FROM tbl_ai_eval_runs ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            return [];
        }
    }
}
