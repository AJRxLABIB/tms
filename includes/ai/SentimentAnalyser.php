<?php
/**
 * SentimentAnalyser.php — AI-powered review sentiment analysis
 * Runs automatically when new reviews are submitted.
 */

require_once __DIR__ . '/AIClient.php';

class SentimentAnalyser {

    private AIClient $ai;
    private PDO      $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * Analyse a single review and store the result.
     */
    public function analyse(int $reviewId, string $reviewText, int $rating): array {
        // Phase 2c: deterministic, explainable fake-review heuristics run
        // regardless of whether the AI call succeeds — these don't cost an
        // API call and give a verifiable reason (not just an AI's opinion)
        // for why a review might warrant a manual look.
        [$isSuspicious, $suspiciousReason] = $this->detectSuspiciousPatterns($reviewId, $reviewText, $rating);

        $system = "You are a sentiment analysis engine for a travel platform. Analyse reviews and return ONLY JSON. No other text.";

        $userMsg = "Review (rating: {$rating}/5): \"{$reviewText}\"

Return: {
  \"sentiment\": \"positive|neutral|negative\",
  \"score\": <0.000-1.000 confidence>,
  \"keywords\": [\"<key word/phrase>\"],
  \"summary\": \"<1 sentence neutral summary of the review>\",
  \"issues\": [\"<specific complaint if any>\"],
  \"highlights\": [\"<specific praise if any>\"]
}";

        $result = $this->ai->ask($system, $userMsg, '', 'system');

        if (!$result['success']) {
            // Fallback: rule-based
            $sentiment = $rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'neutral');
            return $this->saveSentiment($reviewId, $sentiment, $rating / 5, [], "Rating {$rating}/5 review.", [], [], $isSuspicious, $suspiciousReason);
        }

        $data = $this->ai->parseJson($result['content']);
        if (!$data) {
            $sentiment = $rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'neutral');
            return $this->saveSentiment($reviewId, $sentiment, $rating / 5, [], "Rating {$rating}/5 review.", [], [], $isSuspicious, $suspiciousReason);
        }

        return $this->saveSentiment(
            $reviewId,
            $data['sentiment'] ?? 'neutral',
            (float)($data['score'] ?? 0.5),
            $data['keywords'] ?? [],
            $data['summary'] ?? '',
            $data['issues'] ?? [],
            $data['highlights'] ?? [],
            $isSuspicious,
            $suspiciousReason
        );
    }

    /**
     * Lightweight, deterministic fake/spam-review signals — no AI call
     * needed, so this runs even when Gemini is unavailable or rate-limited,
     * and gives admins a concrete, checkable reason rather than an opaque
     * "AI flagged this." Any single signal is a soft flag for manual review,
     * not an automatic removal — false positives are possible and a human
     * should still make the call.
     */
    private function detectSuspiciousPatterns(int $reviewId, string $reviewText, int $rating): array {
        $text = trim($reviewText);
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $text));

        // Signal 1: exact-duplicate text already posted (by anyone) — a
        // classic copy-paste fake-review pattern.
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM tblreviews WHERE id != ? AND LOWER(TRIM(ReviewText)) = ?"
            );
            $stmt->execute([$reviewId, $normalized]);
            if ((int)$stmt->fetchColumn() > 0) {
                return [true, 'Identical review text found on another review (possible copy-paste/fake).'];
            }
        } catch (Exception $e) { /* fail-soft — treat as not suspicious rather than blocking analysis */ }

        // Signal 2: promotional spam (URLs/contact info in a review, which
        // isn't what a genuine trip review looks like).
        if (preg_match('/https?:\/\/|www\.|\+?\d{10,}/i', $text)) {
            return [true, 'Contains a URL or phone number — possible spam/promotional content.'];
        }

        // Signal 3: extremely short but maximum rating — low-effort pattern
        // common in incentivized/fake 5-star review farming. Deliberately a
        // soft signal (short + genuine 5-star reviews absolutely exist), so
        // this is presented to admins as "worth a glance," not a verdict.
        if ($rating === 5 && mb_strlen($text) > 0 && mb_strlen($text) < 12) {
            return [true, 'Very short text paired with a maximum rating — low-effort pattern, may be genuine.'];
        }

        return [false, ''];
    }


    /**
     * Analyse all unanalysed reviews in the database.
     * Call this from a cron job or admin panel.
     */
    public function batchAnalyse(int $limit = 10): int {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.ReviewText, r.Rating
                 FROM tblreviews r
                 LEFT JOIN tbl_review_sentiment s ON s.review_id = r.id
                 WHERE s.id IS NULL AND r.Status = 1
                 LIMIT " . (int)$limit
            );
            $stmt->execute();
            $reviews = $stmt->fetchAll(PDO::FETCH_OBJ);

            $count = 0;
            foreach ($reviews as $r) {
                if (!empty($r->ReviewText)) {
                    $this->analyse($r->id, $r->ReviewText, (int)$r->Rating);
                    $count++;
                    usleep(500000); // 0.5s delay between AI calls
                }
            }
            return $count;
        } catch (Exception $e) {
            error_log('[TMS Sentiment] Batch error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get sentiment summary for a package (for admin dashboard).
     */
    public function getPackageSentiment(int $packageId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT s.sentiment, COUNT(*) as cnt, AVG(s.score) as avg_score
                 FROM tbl_review_sentiment s
                 JOIN tblreviews r ON r.id = s.review_id
                 WHERE r.PackageId = ?
                 GROUP BY s.sentiment"
            );
            $stmt->execute([$packageId]);
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

            $result = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'total' => 0];
            foreach ($rows as $row) {
                $result[$row->sentiment] = (int)$row->cnt;
                $result['total'] += (int)$row->cnt;
            }
            return $result;
        } catch (Exception $e) {
            return ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'total' => 0];
        }
    }

    /**
     * Reviews flagged by detectSuspiciousPatterns() for the admin dashboard's
     * fraud-detection panel. Each result includes WHY it was flagged, since
     * these are heuristic soft-flags for human review, not automatic removals.
     */
    public function getSuspiciousReviews(int $limit = 20): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.id, r.UserName, r.UserEmail, r.Rating, r.ReviewText, r.PostingDate,
                        p.PackageName, s.suspicious_reason
                 FROM tbl_review_sentiment s
                 JOIN tblreviews r ON r.id = s.review_id
                 LEFT JOIN tbltourpackages p ON p.PackageId = r.PackageId
                 WHERE s.is_suspicious = 1
                 ORDER BY r.PostingDate DESC
                 LIMIT " . (int)$limit
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log('[TMS] ' . __METHOD__ . ' failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Aggregates the most common complaint/praise phrases across all
     * analysed reviews — surfaces the "issues"/"highlights" data that used
     * to be requested from the AI and then silently discarded.
     */
    public function getTopIssuesAndHighlights(int $limit = 8): array {
        try {
            $issues = $this->db->query(
                "SELECT issues FROM tbl_review_sentiment WHERE issues IS NOT NULL AND issues != '' AND issues != '[]'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $highlights = $this->db->query(
                "SELECT highlights FROM tbl_review_sentiment WHERE highlights IS NOT NULL AND highlights != '' AND highlights != '[]'"
            )->fetchAll(PDO::FETCH_COLUMN);

            $countPhrases = function (array $rows) use ($limit) {
                $counts = [];
                foreach ($rows as $row) {
                    $items = json_decode($row, true);
                    if (!is_array($items)) continue;
                    foreach ($items as $phrase) {
                        $phrase = trim((string)$phrase);
                        if ($phrase === '') continue;
                        $key = mb_strtolower($phrase);
                        $counts[$key] = ($counts[$key] ?? ['phrase' => $phrase, 'count' => 0]);
                        $counts[$key]['count']++;
                    }
                }
                usort($counts, fn($a, $b) => $b['count'] <=> $a['count']);
                return array_slice(array_values($counts), 0, $limit);
            };

            return [
                'issues'     => $countPhrases($issues),
                'highlights' => $countPhrases($highlights),
            ];
        } catch (Exception $e) {
            return ['issues' => [], 'highlights' => []];
        }
    }

    /**
     * Same phrase-aggregation as getTopIssuesAndHighlights(), scoped to one
     * package — this is what actually powers the customer-facing "AI
     * Review Summary" on package-details.php. The global version above is
     * admin-only (site-wide trends); a customer deciding whether to book a
     * specific package needs the summary for *that* package, which nothing
     * previously exposed even though the sentiment data + phrase-extraction
     * logic already existed.
     */
    public function getPackageHighlights(int $packageId, int $limit = 6): array {
        try {
            $issues = $this->db->prepare(
                "SELECT s.issues FROM tbl_review_sentiment s JOIN tblreviews r ON r.id = s.review_id
                 WHERE r.PackageId = ? AND s.issues IS NOT NULL AND s.issues != '' AND s.issues != '[]'"
            );
            $issues->execute([$packageId]);
            $issueRows = $issues->fetchAll(PDO::FETCH_COLUMN);

            $highlights = $this->db->prepare(
                "SELECT s.highlights FROM tbl_review_sentiment s JOIN tblreviews r ON r.id = s.review_id
                 WHERE r.PackageId = ? AND s.highlights IS NOT NULL AND s.highlights != '' AND s.highlights != '[]'"
            );
            $highlights->execute([$packageId]);
            $highlightRows = $highlights->fetchAll(PDO::FETCH_COLUMN);

            $countPhrases = function (array $rows) use ($limit) {
                $counts = [];
                foreach ($rows as $row) {
                    $items = json_decode($row, true);
                    if (!is_array($items)) continue;
                    foreach ($items as $phrase) {
                        $phrase = trim((string)$phrase);
                        if ($phrase === '') continue;
                        $key = mb_strtolower($phrase);
                        $counts[$key] = ($counts[$key] ?? ['phrase' => $phrase, 'count' => 0]);
                        $counts[$key]['count']++;
                    }
                }
                usort($counts, fn($a, $b) => $b['count'] <=> $a['count']);
                return array_slice(array_values($counts), 0, $limit);
            };

            return [
                'issues'     => $countPhrases($issueRows),
                'highlights' => $countPhrases($highlightRows),
                'sentiment'  => $this->getPackageSentiment($packageId),
            ];
        } catch (Exception $e) {
            return ['issues' => [], 'highlights' => [], 'sentiment' => ['positive'=>0,'neutral'=>0,'negative'=>0,'total'=>0]];
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function saveSentiment(
        int $reviewId, string $sentiment, float $score, array $keywords, string $summary,
        array $issues = [], array $highlights = [], bool $isSuspicious = false, string $suspiciousReason = ''
    ): array {
        $result = [
            'review_id'    => $reviewId,
            'sentiment'    => $sentiment,
            'score'        => $score,
            'keywords'     => $keywords,
            'summary'      => $summary,
            'issues'       => $issues,
            'highlights'   => $highlights,
            'is_suspicious'=> $isSuspicious,
            'suspicious_reason' => $suspiciousReason,
        ];

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_review_sentiment
                    (review_id, sentiment, score, keywords, summary, issues, highlights, is_suspicious, suspicious_reason, analysed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                   sentiment=VALUES(sentiment), score=VALUES(score),
                   keywords=VALUES(keywords), summary=VALUES(summary),
                   issues=VALUES(issues), highlights=VALUES(highlights),
                   is_suspicious=VALUES(is_suspicious), suspicious_reason=VALUES(suspicious_reason),
                   analysed_at=NOW()"
            );
            $stmt->execute([
                $reviewId,
                $sentiment,
                $score,
                implode(', ', $keywords),
                $summary,
                json_encode(array_values($issues), JSON_UNESCAPED_UNICODE),
                json_encode(array_values($highlights), JSON_UNESCAPED_UNICODE),
                $isSuspicious ? 1 : 0,
                $suspiciousReason ?: null,
            ]);
        } catch (Exception $e) {
            error_log('[TMS Sentiment] Save error: ' . $e->getMessage());
        }

        return $result;
    }
}
