<?php
/**
 * ItineraryChecker — deterministic consistency checks on a generated itinerary. No AI, no network, no invented numbers.
 *
 * It only flags things that are internally contradictory or impossible given the plan's own times, durations and costs:
 *   time_order      an activity starts earlier than the one before it
 *   overlap         an activity runs past the start of the next one
 *   overloaded_day  more than 12 h of scheduled activities, or a 16 h+ span from first start to last end
 *   early_late      an activity starting before 05:00 or ending after 24:00
 *   day_cost        entry costs add up to more than 125% of that day's own daily_budget
 *   day_count       number of days differs from the requested duration
 *   day_numbers     day numbers are not 1..n
 *   day_dates       dates are not consecutive (or the first differs from the requested start)
 *   cost_sum        estimated_cost.total differs from the sum of its parts by more than 1%
 *   over_budget     estimated total is above the budget the traveller stated
 *   distance        two consecutive activities at DIFFERENT known places are farther apart (straight line) than the gap between
 *                   them could cover at 120 km/h. Straight-line distance is a lower bound on real distance, so this only fires when it
 *                   is impossible by road; flights are not modelled. It uses coordinates supplied by a geocoder callable (by default
 *                   the locally cached Explore destinations, no network) and reports how many pairs it could actually check.
 * It never estimates travel times, never rewrites the plan, and says what it could not check ("skipped").
 * A clean result means "no contradictions found by these checks", NOT "the plan is good or feasible in practice".
 */
final class ItineraryChecker
{
    private const MAX_ACTIVITY_MIN = 12 * 60;
    private const MAX_SPAN_MIN = 16 * 60;
    private const OVERLAP_TOLERANCE_MIN = 10;
    private const GROUND_KMH = 120;

    /** "09:30", "9:30 AM", "2pm", "14.00" -> minutes after midnight, or null */
    public static function parseTime($s): ?int
    {
        if (!is_string($s) && !is_numeric($s)) return null;
        $s = strtolower(trim((string)$s));
        if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?$/', $s, $m)) return null;
        $h = (int)$m[1]; $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0; $ap = $m[3] ?? '';
        if ($ap === 'pm' && $h < 12) $h += 12; if ($ap === 'am' && $h === 12) $h = 0;
        return ($h > 23 || $min > 59) ? null : $h * 60 + $min;
    }

    /** "2 hours", "90 min", "1.5h", "2-3 hours" (lower bound), "1 hour 30 minutes" -> minutes, or null when unclear ("half day", "overnight") */
    public static function parseDuration($s): ?int
    {
        if (is_int($s) || is_float($s)) return $s > 0 ? (int)round($s) : null;
        if (!is_string($s)) return null;
        $t = strtolower(trim($s));
        if ($t === '' || preg_match('/day|night|overnight|all/', $t)) return null;
        $total = 0.0; $found = false;
        if (preg_match_all('/(\d+(?:\.\d+)?)(?:\s*[-–]\s*\d+(?:\.\d+)?)?\s*(hours?|hrs?|h|minutes?|mins?|m)\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) { $found = true; $total += ((float)$x[1]) * (str_starts_with($x[2], 'h') ? 60 : 1); }
        }
        return $found && $total > 0 ? (int)round($total) : null;
    }

    private static function hhmm(int $min): string { return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60); }

    /** Default geocoder: coordinates already cached by Explore (tbl_explore_destinations). No network. @return callable(string):?array{lat:float,lng:float} */
    public static function cachedGeocoder(?PDO $db): callable
    {
        $memo = [];
        return function (string $place) use ($db, &$memo) {
            $k = mb_strtolower(trim($place), 'UTF-8');
            if ($k === '' || !$db) return null;
            if (array_key_exists($k, $memo)) return $memo[$k];
            $r = null;
            try {
                $st = $db->prepare('SELECT lat, lng FROM tbl_explore_destinations WHERE (LOWER(display_name) = ? OR LOWER(place_key) = ?) AND lat IS NOT NULL AND lng IS NOT NULL LIMIT 1');
                $st->execute([$k, $k]); $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && ((float)$row['lat'] != 0.0 || (float)$row['lng'] != 0.0)) $r = ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng']];
            } catch (Throwable $e) { $r = null; }
            return $memo[$k] = $r;
        };
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param array $it itinerary as produced by ItineraryPlanner
     * @param array $trip requested trip data (duration, start_date, budget, travelers) - all optional
     * @param ?callable $geocoder place name -> ['lat'=>,'lng'=>] or null; null disables the distance check ("skipped")
     * @return array{issues:array<int,array{severity:string,day:?int,code:string,message:string}>,errors:int,warnings:int,infos:int,checked:string[],skipped:string[],distance_pairs_checked:int}
     */
    public static function check(array $it, array $trip = [], ?callable $geocoder = null): array
    {
        $issues = []; $checked = ['time_order', 'overlap', 'overloaded_day', 'early_late', 'day_cost', 'day_numbers', 'cost_sum']; $skipped = [];
        $add = function (string $sev, ?int $day, string $code, string $msg) use (&$issues) { $issues[] = ['severity' => $sev, 'day' => $day, 'code' => $code, 'message' => $msg]; };
        $days = is_array($it['days'] ?? null) ? array_values($it['days']) : [];
        if (!$days) { $add('error', null, 'no_days', 'The plan has no days to check.'); return self::wrap($issues, $checked, ['all'], 0); }

        if (isset($trip['duration']) && (int)$trip['duration'] > 0) { $checked[] = 'day_count'; if (count($days) !== (int)$trip['duration']) $add('warning', null, 'day_count', 'The plan has ' . count($days) . ' day(s) but ' . (int)$trip['duration'] . ' were requested.'); }
        else $skipped[] = 'day_count (no requested duration given)';

        $pairs = 0; $prevDate = null; $prevNum = 0; $datesOk = true; $datesSeen = 0;
        foreach ($days as $i => $d) {
            $n = isset($d['day']) && is_numeric($d['day']) ? (int)$d['day'] : null;
            if ($n !== $i + 1) $add('warning', $n, 'day_numbers', 'Day numbering is not 1..' . count($days) . ' (found ' . ($n ?? 'none') . ' at position ' . ($i + 1) . ').');
            if (!empty($d['date']) && is_string($d['date']) && ($ts = strtotime($d['date'])) !== false) {
                $datesSeen++; $ymd = date('Y-m-d', $ts);
                if ($prevDate !== null && date('Y-m-d', strtotime($prevDate . ' +1 day')) !== $ymd) { $datesOk = false; $add('warning', $n, 'day_dates', "Date $ymd does not follow the previous day ($prevDate)."); }
                $prevDate = $ymd;
                if ($i === 0 && !empty($trip['start_date']) && ($st = strtotime((string)$trip['start_date'])) !== false && date('Y-m-d', $st) !== $ymd) $add('warning', $n, 'day_dates', "The plan starts on $ymd but the requested start date is " . date('Y-m-d', $st) . '.');
            }
            $sched = is_array($d['schedule'] ?? null) ? array_values($d['schedule']) : [];
            $rows = [];
            foreach ($sched as $s) {
                if (!is_array($s)) continue;
                $rows[] = ['t' => self::parseTime($s['time'] ?? null), 'dur' => self::parseDuration($s['duration'] ?? null), 'type' => strtolower((string)($s['type'] ?? '')), 'loc' => trim((string)($s['location'] ?? '')), 'act' => (string)($s['activity'] ?? ''), 'cost' => is_numeric($s['cost'] ?? null) ? (float)$s['cost'] : null];
            }
            $timed = array_values(array_filter($rows, fn($r) => $r['t'] !== null));
            $span0 = null; $spanEnd = null; $activityMin = 0;
            foreach ($timed as $k => $r) {
                $end = $r['dur'] !== null ? $r['t'] + $r['dur'] : null;
                if ($r['type'] !== 'accommodation') {
                    if ($r['t'] < 5 * 60) $add('warning', $n, 'early_late', 'Starts at ' . self::hhmm($r['t']) . ': "' . mb_substr($r['act'], 0, 50) . '".');
                    if ($end !== null && $end > 24 * 60) $add('warning', $n, 'early_late', 'Runs past midnight (ends ' . self::hhmm($end) . '): "' . mb_substr($r['act'], 0, 50) . '".');
                    $span0 = $span0 === null ? $r['t'] : min($span0, $r['t']);
                    if ($end !== null) $spanEnd = $spanEnd === null ? $end : max($spanEnd, $end);
                    if ($r['type'] !== 'meal' && $r['dur'] !== null) $activityMin += $r['dur'];
                }
                if (isset($timed[$k + 1])) {
                    $nx = $timed[$k + 1];
                    if ($nx['t'] < $r['t']) { $add('warning', $n, 'time_order', 'Times go backwards: ' . self::hhmm($r['t']) . ' then ' . self::hhmm($nx['t']) . '.'); continue; }
                    if ($end !== null && $end - $nx['t'] > self::OVERLAP_TOLERANCE_MIN) {
                        $over = $end - $nx['t'];
                        $add($over > 60 ? 'error' : 'warning', $n, 'overlap', '"' . mb_substr($r['act'], 0, 40) . '" (' . self::hhmm($r['t']) . ' + ' . $r['dur'] . ' min) overlaps the next item at ' . self::hhmm($nx['t']) . " by $over min.");
                    }
                    // distance: different known places, gap too short for the straight line at 120 km/h
                    if ($geocoder && $end !== null && $r['loc'] !== '' && $nx['loc'] !== '' && mb_strtolower($r['loc'], 'UTF-8') !== mb_strtolower($nx['loc'], 'UTF-8') && $nx['t'] >= $end) {
                        $a = $geocoder($r['loc']); $b = $geocoder($nx['loc']);
                        if ($a && $b) {
                            $pairs++; $km = self::haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng']); $gap = $nx['t'] - $end;
                            if ($km > 1.0 && $km > ($gap / 60) * self::GROUND_KMH) $add('warning', $n, 'distance', sprintf('"%s" to "%s" are at least %.0f km apart in a straight line, but only %d min are left between the two (that would need more than %d km/h by road). Check how you get there.', mb_substr($r['loc'], 0, 40), mb_substr($nx['loc'], 0, 40), $km, $gap, self::GROUND_KMH));
                        }
                    }
                }
            }
            if ($activityMin > self::MAX_ACTIVITY_MIN) $add('warning', $n, 'overloaded_day', sprintf('%.1f hours of activities are scheduled (more than %d).', $activityMin / 60, self::MAX_ACTIVITY_MIN / 60));
            elseif ($span0 !== null && $spanEnd !== null && $spanEnd - $span0 > self::MAX_SPAN_MIN) $add('warning', $n, 'overloaded_day', sprintf('The day runs %.1f hours from first start to last end.', ($spanEnd - $span0) / 60));
            $costs = array_filter(array_column($rows, 'cost'), fn($c) => $c !== null);
            if ($costs && isset($d['daily_budget']) && is_numeric($d['daily_budget']) && (float)$d['daily_budget'] > 0 && array_sum($costs) > 1.25 * (float)$d['daily_budget'])
                $add('warning', $n, 'day_cost', sprintf("Entry costs add up to %s but the day's own budget is %s.", number_format(array_sum($costs)), number_format((float)$d['daily_budget'])));
        }
        if ($datesSeen >= 1) $checked[] = 'day_dates'; else $skipped[] = 'day_dates (no dates in the plan)';

        $ec = is_array($it['estimated_cost'] ?? null) ? $it['estimated_cost'] : null;
        if ($ec && isset($ec['total']) && is_numeric($ec['total'])) {
            $parts = 0.0; $np = 0; foreach (['accommodation', 'transport', 'food', 'activities', 'miscellaneous'] as $p) if (isset($ec[$p]) && is_numeric($ec[$p])) { $parts += (float)$ec[$p]; $np++; }
            if ($np >= 2 && abs($parts - (float)$ec['total']) > 0.01 * max(1.0, (float)$ec['total'])) $add('warning', null, 'cost_sum', sprintf('The cost total (%s) does not equal the sum of its parts (%s).', number_format((float)$ec['total']), number_format($parts)));
            if (isset($trip['budget']) && is_numeric($trip['budget']) && (float)$trip['budget'] > 0) {
                $checked[] = 'over_budget';
                if ((float)$ec['total'] > (float)$trip['budget']) $add('warning', null, 'over_budget', sprintf('The plan\'s estimated total (%s) is above the stated budget (%s). The estimate itself is an AI figure.', number_format((float)$ec['total']), number_format((float)$trip['budget'])));
            } else $skipped[] = 'over_budget (no budget given)';
        } else { $skipped[] = 'cost_sum / over_budget (plan has no estimated total)'; }
        if ($geocoder) $checked[] = 'distance'; else $skipped[] = 'distance (no coordinates source)';

        return self::wrap($issues, $checked, $skipped, $pairs);
    }

    private static function wrap(array $issues, array $checked, array $skipped, int $pairs): array
    {
        $c = fn(string $s) => count(array_filter($issues, fn($i) => $i['severity'] === $s));
        return ['issues' => $issues, 'errors' => $c('error'), 'warnings' => $c('warning'), 'infos' => $c('info'), 'checked' => array_values(array_unique($checked)), 'skipped' => $skipped, 'distance_pairs_checked' => $pairs];
    }

    /** Escaped, self-contained HTML note for a check result (explicit colours so it reads the same in light and dark pages). */
    public static function summaryHtml(array $r, string $label = 'Plan check'): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $st = 'background:#f1f5f9;color:#1f2937;border:1px solid #cbd5e1;border-radius:8px;padding:10px 14px;margin:0 0 16px;font-size:13px;';
        $n = count($r['issues'] ?? []);
        if ($n === 0) return '<div class="mt-plancheck" role="note" style="' . $st . '"><strong>' . $e($label) . ':</strong> no timing, date or cost contradictions found by our automatic checks. That does not guarantee the plan works in practice (opening hours, queues and travel times are not checked).</div>';
        $h = '<div class="mt-plancheck" role="note" style="' . $st . '"><strong>' . $e($label) . ':</strong> ' . $n . ' thing' . ($n === 1 ? '' : 's') . ' to look at before you rely on this plan.<ul style="margin:6px 0 0 18px;padding:0;color:#92400e;">';
        foreach (array_slice($r['issues'], 0, 6) as $i) $h .= '<li>' . ($i['day'] !== null ? 'Day ' . (int)$i['day'] . ': ' : '') . $e($i['message']) . '</li>';
        if ($n > 6) $h .= '<li>and ' . ($n - 6) . ' more</li>';
        return $h . '</ul><div style="margin-top:6px;color:#475569;font-size:12px;">Automatic checks of the plan\'s own times, dates and costs; they cannot tell whether the plan is good, only where it contradicts itself.</div></div>';
    }
}
