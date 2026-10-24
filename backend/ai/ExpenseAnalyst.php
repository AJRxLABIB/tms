<?php
/**
 * ExpenseAnalyst.php — AI Expense Tracker with analytics and budget insights
 *
 * Same "real data first, AI narrative on top" pattern as BudgetOptimizer and
 * CrowdPredictor. The category breakdown and budget-vs-actual comparison are
 * exact SQL aggregates over what the user actually logged in `tblexpenses` —
 * nothing here is invented. The AI is only used to turn those real numbers
 * into a short, readable insight sentence, and that step is fail-soft: the
 * deterministic breakdown is already a complete, useful result on its own.
 */

require_once __DIR__ . '/AIClient.php';

class ExpenseAnalyst {

    private AIClient $ai;
    private PDO      $db;

    private const CATEGORIES = ['accommodation', 'food', 'transport', 'activities', 'shopping', 'other'];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    public static function categories(): array {
        return self::CATEGORIES;
    }

    public function addExpense(string $email, ?int $bookingId, string $category, int $amount, string $description, string $date): int {
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'other';
        $stmt = $this->db->prepare(
            "INSERT INTO tblexpenses (UserEmail, BookingId, Category, Amount, Description, ExpenseDate)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$email, $bookingId ?: null, $category, $amount, $description, $date]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteExpense(int $expenseId, string $email): bool {
        // Scoped to $email so one user can never delete another's expense row,
        // even if they guess/tamper with an ExpenseId.
        $stmt = $this->db->prepare("DELETE FROM tblexpenses WHERE ExpenseId = ? AND UserEmail = ?");
        $stmt->execute([$expenseId, $email]);
        return $stmt->rowCount() > 0;
    }

    public function getExpenses(string $email, ?int $bookingId = null): array {
        if ($bookingId) {
            $stmt = $this->db->prepare("SELECT * FROM tblexpenses WHERE UserEmail = ? AND BookingId = ? ORDER BY ExpenseDate DESC, ExpenseId DESC");
            $stmt->execute([$email, $bookingId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM tblexpenses WHERE UserEmail = ? ORDER BY ExpenseDate DESC, ExpenseId DESC");
            $stmt->execute([$email]);
        }
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** Real category totals — deterministic SQL, this is the core of "analytics." */
    public function getCategoryBreakdown(string $email, ?int $bookingId = null): array {
        $sql = "SELECT Category, SUM(Amount) AS total, COUNT(*) AS cnt FROM tblexpenses WHERE UserEmail = ?";
        $params = [$email];
        if ($bookingId) { $sql .= " AND BookingId = ?"; $params[] = $bookingId; }
        $sql .= " GROUP BY Category";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $breakdown = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            $breakdown[$row->Category] = ['total' => (int)$row->total, 'count' => (int)$row->cnt];
            $total += (int)$row->total;
        }
        return ['by_category' => $breakdown, 'total' => $total];
    }

    /**
     * Compares real logged spending against a budget figure. The budget can
     * come from an actual booking's TotalCost (real platform data) or from
     * an AI-generated itinerary's estimated_cost breakdown if one exists for
     * that booking — both are real numbers already in this system, not
     * invented for this comparison.
     */
    public function getBudgetComparison(string $email, int $bookingId): array {
        $breakdown = $this->getCategoryBreakdown($email, $bookingId);

        $stmt = $this->db->prepare("SELECT TotalCost FROM tblbooking WHERE BookingId = ? AND UserEmail = ? LIMIT 1");
        $stmt->execute([$bookingId, $email]);
        $budget = (int)($stmt->fetchColumn() ?: 0);

        $spent = $breakdown['total'];
        $remaining = $budget - $spent;
        $percentUsed = $budget > 0 ? round(($spent / $budget) * 100) : null;

        return [
            'budget'        => $budget,
            'spent'         => $spent,
            'remaining'     => $remaining,
            'percent_used'  => $percentUsed,
            'by_category'   => $breakdown['by_category'],
            'over_budget'   => $budget > 0 && $spent > $budget,
        ];
    }

    /**
     * One AI-written sentence of insight on top of the real numbers above.
     * Fail-soft — a deterministic fallback sentence covers the case where
     * the AI call doesn't succeed, so this method always returns something
     * useful.
     */
    public function generateInsight(string $email, ?int $bookingId = null): string {
        $comparison = $bookingId ? $this->getBudgetComparison($email, $bookingId) : null;
        $breakdown  = $comparison ?? $this->getCategoryBreakdown($email);

        if (empty($breakdown['by_category']) && empty($breakdown['spent']) && ($breakdown['total'] ?? 0) === 0) {
            return 'No expenses logged yet — add your first expense to start tracking.';
        }

        $topCategory = null; $topAmount = 0;
        foreach (($breakdown['by_category'] ?? []) as $cat => $data) {
            if ($data['total'] > $topAmount) { $topAmount = $data['total']; $topCategory = $cat; }
        }

        // The category name is free text typed by the traveller: strip markup/control characters and cap it before it enters the prompt.
        $topCategory = $topCategory === null ? 'n/a' : (trim(mb_substr(str_replace(['{', '}', '<', '>', '`', '"'], ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$topCategory) ?? ''), 0, 40, 'UTF-8')) ?: 'n/a');
        $prompt = "Real expense data — ";
        if ($comparison) {
            $prompt .= "Budget: {$comparison['budget']} BDT, spent so far: {$comparison['spent']} BDT ({$comparison['percent_used']}% of budget), "
                     . "top spending category: {$topCategory} ({$topAmount} BDT).";
        } else {
            $prompt .= "Total spent across all trips: {$breakdown['total']} BDT, top category: {$topCategory} ({$topAmount} BDT).";
        }
        $prompt .= " Give ONE concise, actionable sentence of budget insight/advice based on these real numbers.";

        $cacheKey = 'expense_insight_' . md5($email . '_' . $bookingId . '_' . ($breakdown['total'] ?? $comparison['spent'] ?? 0));
        $result = $this->ai->ask(
            "You are a personal travel finance advisor. Be concise and specific, referencing the real numbers given.",
            $prompt,
            $cacheKey,
            $email,
            feature: 'expense'
        );

        if ($result['success']) {
            $reply = trim(mb_substr(strip_tags((string)$result['content']), 0, 400, 'UTF-8'));
            // Every amount the model quotes must be one of the real figures it was given; otherwise the deterministic sentence below is used.
            $known = array_filter(array_map('intval', [
                $comparison['budget'] ?? 0, $comparison['spent'] ?? 0, $comparison['percent_used'] ?? 0, abs((int)($comparison['remaining'] ?? 0)),
                $breakdown['total'] ?? 0, $topAmount,
            ]));
            preg_match_all('/\d[\d,]*(?:\.\d+)?/', $reply, $mm);
            $foreign = false;
            foreach ($mm[0] as $n) { $v = (int)round((float)str_replace(',', '', $n)); if ($v >= 100 && !in_array($v, $known, true)) { $foreign = true; break; } }
            if ($reply !== '' && !$foreign) return $reply;
        }

        // Deterministic fallback — still useful without the AI call.
        if ($comparison && $comparison['over_budget']) {
            return "You've gone " . abs($comparison['remaining']) . " BDT over your " . $comparison['budget'] . " BDT budget — consider trimming " . $topCategory . " spending.";
        }
        if ($comparison) {
            return "You've used {$comparison['percent_used']}% of your budget so far, with {$topCategory} as your largest expense category.";
        }
        return "Your largest expense category so far is {$topCategory} at {$topAmount} BDT.";
    }
}
