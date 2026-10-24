<?php
require_once __DIR__ . '/BrainText.php';
require_once __DIR__ . '/BrainKnowledge.php';
require_once __DIR__ . '/BrainIntent.php';
require_once __DIR__ . '/BrainEntities.php';
require_once __DIR__ . '/BrainPlanner.php';

/**
 * BrainChat - answers travel questions from the built-in knowledge base, with no language model.
 * reply() returns ['reply'=>string,'suggestions'=>string[],'intent'=>string] or null when the question is not one it
 * understands (the caller then falls back to the catalogue search).
 */
final class BrainChat
{
    private const PRICE_NOTE = 'Prices are approximate and change; confirm before you pay.';

    private const NEEDS_PLACE = ['how_to_reach', 'best_time', 'cost', 'things_to_do', 'food', 'stay', 'safety', 'packing', 'weather', 'plan_trip'];

    /**
     * Answer one chat message. $context is the conversation memory returned by the previous call (store it in the session).
     * @return array{reply:string,suggestions:string[],intent:string,state:array}|null  null = not understood (caller uses the catalogue / logs it)
     */
    public static function reply(string $message, array $context = []): ?array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message, 'UTF-8') > 600) return null;
        $bn = (bool)preg_match('/[\x{0980}-\x{09FF}]/u', $message);
        $ctx = $context + ['place' => null, 'intent' => null, 'days' => null, 'travelers' => null, 'budget' => null, 'month' => null, 'origin' => null, 'tier' => null, 'interests' => [], 'last_total' => null];

        // "X and Y" with two different question types: answer both
        $parts = self::splitMulti($message);
        if (count($parts) > 1) {
            $outs = []; $state = $ctx; $sugg = [];
            $whole = BrainEntities::parse($message)['places'];
            if ($whole) $state['place'] = $whole[0];   // "best time and how to reach Bandarban": both parts are about Bandarban
            foreach ($parts as $part) {
                $r = self::single($part, $state);
                if (!$r) { $outs = []; break; }
                $outs[] = $r['reply']; $state = $r['state']; $sugg = $r['suggestions'];
            }
            if (count($outs) > 1) return ['reply' => ($bn ? self::bnIntro() : '') . implode("\n\n", $outs), 'suggestions' => $sugg, 'intent' => 'multi', 'state' => $state];
        }
        $r = self::single($message, $ctx);
        if ($r && $bn) $r['reply'] = self::bnIntro() . $r['reply'];
        return $r;
    }

    private static function bnIntro(): string { return "আপনার প্রশ্নের উত্তর (ইংরেজিতে):\n"; }

    /** Split "how to reach sylhet and best time" into clauses when each is its own question. */
    private static function splitMulti(string $m): array
    {
        $bits = preg_split('/\s*(?:\band\b|&|,|;|\bও\b|\bএবং\b)\s*/iu', $m) ?: [$m];
        $bits = array_values(array_filter(array_map('trim', $bits), fn($x) => mb_strlen($x, 'UTF-8') >= 6));
        if (count($bits) < 2 || count($bits) > 3) return [$m];
        $seen = [];
        foreach ($bits as $b) { $c = BrainIntent::classify($b); if ($c['intent'] === 'unknown' || $c['confidence'] < 0.45 || in_array($c['intent'], ['greeting', 'thanks', 'suggest', 'compare', 'plan_trip'], true)) return [$m]; $seen[$c['intent']] = 1; }
        return count($seen) === count($bits) ? $bits : [$m];
    }

    private static function single(string $message, array $ctx): ?array
    {
        $cls = BrainIntent::classify($message);
        $e = BrainEntities::parse($message);
        $intent = $cls['intent']; $conf = $cls['confidence'];
        $places = $e['places'];
        $n = ' ' . BrainText::norm($message) . ' ';

        // refinement of the last plan: "make it cheaper", "add a day", "for 4 people", "more food"
        $ref = self::refine($message, $n, $e, $ctx);
        if ($ref !== null) { $intent = 'plan_trip'; $e = $ref; $places = [$ctx['place']]; $conf = 1.0; }
        elseif (count($places) >= 2 && ($intent === 'unknown' || $intent === 'compare' || preg_match('/ (or|vs|versus|compare|better|nki|na) /', $n))) $intent = 'compare';
        elseif ($intent === 'unknown' || $conf < 0.45) {
            // a bare follow-up: "and Sylhet?", "what about 4 people", "in June", "under 20k"
            $bare = !empty($ctx['intent']) && mb_strlen($message, 'UTF-8') < 60 && ($places || $e['days'] || $e['travelers'] || $e['budget'] || $e['month'] || $e['tier']);
            if ($bare) {
                $intent = $ctx['intent'];
                if (!$places && !$e['days'] && !$e['travelers'] && !$e['budget'] && $e['month'] && in_array($intent, ['food', 'things_to_do', 'stay', 'packing', 'safety', 'how_to_reach'], true)) $intent = 'weather';
            }
            elseif (!in_array($intent, ['greeting', 'thanks'], true)) {
                $miss = self::unknownPlace($message, $n);
                if ($miss !== null) return $miss + ['state' => $ctx];
                return null;
            }
        }
        // a place name that is not in the knowledge base must not silently fall back to the previous place
        if (!$places && in_array($intent, self::NEEDS_PLACE, true) && $ref === null) { $miss = self::unknownPlace($message, $n); if ($miss !== null) return $miss + ['state' => $ctx]; }
        // fill what the message left out from the conversation so far
        if (!$places && !empty($ctx['place']) && in_array($intent, self::NEEDS_PLACE, true)) $places = [$ctx['place']];
        foreach (['days', 'travelers', 'budget', 'month', 'origin', 'tier'] as $k) if (($e[$k] ?? null) === null && !empty($ctx[$k]) && in_array($intent, ['plan_trip', 'cost', 'how_to_reach', 'weather'], true) && ($ref !== null || $k !== 'budget' || $intent !== 'how_to_reach')) $e[$k] = $ctx[$k];

        $d = $places ? BrainKnowledge::get($places[0]) : null;
        if (!$d && in_array($intent, self::NEEDS_PLACE, true) && $intent !== 'plan_trip') {
            $miss = self::unknownPlace($message, $n);
            if ($miss !== null) return $miss + ['state' => $ctx];
            return null;   // "price of package 5": catalogue question
        }
        $r = null;
        switch ($intent) {
            case 'greeting': $r = self::greeting(); break;
            case 'thanks': $r = ['reply' => "You're welcome! Ask me anything about destinations, costs, routes or when to go.", 'suggestions' => ["Plan 3 days in Sylhet", "Best time for Sundarbans", "Where should I go in winter?"]]; break;
            case 'about': $r = self::about(); break;
            case 'suggest': $r = self::suggest($e); break;
            case 'compare': $r = count($places) >= 2 ? self::compare($places[0], $places[1], $e) : null; break;
            default:
                if (!$d) { $r = ['reply' => "Which place do you want to visit? I know " . self::names(8) . " and " . (count(BrainKnowledge::all()) - 8) . " more.", 'suggestions' => ['Plan 3 days in Cox\'s Bazar', 'Sylhet trip for 2', 'Bandarban under 20000']]; break; }
                $r = match ($intent) {
                    'plan_trip' => self::planTrip($d, $e), 'how_to_reach' => self::reachAnswer($d, $e), 'best_time' => self::bestTime($d),
                    'cost' => self::cost($d, $e), 'things_to_do' => self::things($d), 'food' => self::food($d), 'stay' => self::stay($d),
                    'safety' => self::safety($d), 'packing' => self::packing($d), 'weather' => self::weather($d, $e), default => null,
                };
        }
        if (!$r) return null;
        // memory for the next turn
        $st = $ctx;
        if ($d) $st['place'] = $d['id'];
        $st['intent'] = in_array($intent, ['greeting', 'thanks', 'about'], true) ? $ctx['intent'] : $intent;
        foreach (['days', 'travelers', 'budget', 'month', 'origin', 'tier'] as $k) if (($e[$k] ?? null) !== null) $st[$k] = $e[$k];
        if ($e['interests']) $st['interests'] = array_values(array_unique(array_merge((array)$ctx['interests'], $e['interests'])));
        if (isset($r['total'])) $st['last_total'] = $r['total'];
        unset($r['total']);
        $r['intent'] = $intent; $r['place'] = $d['id'] ?? null; $r['state'] = $st;
        return $r;
    }

    /** Entities for a plan change, or null when the message is not a refinement of the last plan. */
    private static function refine(string $m, string $n, array $e, array $ctx): ?array
    {
        if (empty($ctx['place']) || ($ctx['intent'] ?? '') !== 'plan_trip') return null;
        $x = $e; $hit = false;
        $x['days'] = $ctx['days'] ?? 3; $x['travelers'] = $e['travelers'] ?? $ctx['travelers']; $x['budget'] = $e['budget'] ?? $ctx['budget'];
        $x['month'] = $e['month'] ?? $ctx['month']; $x['origin'] = $ctx['origin']; $x['tier'] = $e['tier'] ?? $ctx['tier'];
        $x['interests'] = array_values(array_unique(array_merge((array)$ctx['interests'], $e['interests'])));
        if ($e['places'] && $e['places'][0] !== $ctx['place']) return null;
        if (preg_match('/ (add|one more|another|extra|more) (a )?day| \+1 day| longer| extend /', $n)) { $x['days'] = min(14, (int)$x['days'] + 1); $hit = true; }
        elseif (preg_match('/ (remove|one less|fewer|cut|shorter|less) (a )?day| shorten /', $n)) { $x['days'] = max(1, (int)$x['days'] - 1); $hit = true; }
        elseif ($e['days']) { $x['days'] = $e['days']; $hit = true; }
        if (preg_match('/ (cheaper|cheap|less expensive|lower cost|reduce cost|save money|sosta|কম খরচ) /u', $n)) { $x['tier'] = $x['tier'] === 'luxury' ? 'mid' : 'budget'; if (!empty($ctx['last_total'])) $x['budget'] = (int)round($ctx['last_total'] * 0.8, -2); $hit = true; }
        if (preg_match('/ (luxury|upgrade|better hotel|nicer|premium|more comfortable|expensive) /', $n)) { $x['tier'] = $x['tier'] === 'budget' ? 'mid' : 'luxury'; $x['budget'] = null; $hit = true; }
        if ($e['travelers'] || $e['budget'] || $e['month'] || $e['tier'] || $e['interests']) $hit = true;
        if (preg_match('/ (more|add|include|focus on|i like|we like) /', $n) && $e['interests']) $hit = true;
        return $hit ? $x : null;
    }

    /** "plan a trip to Rome" where Rome is not in the knowledge base: say so honestly, offer close matches, and log it. */
    private static function unknownPlace(string $m, string $n): ?array
    {
        if (!preg_match('/\b(?:to|in|for|visit|visiting|trip to|going to|near|at)\s+([A-Za-z\x{0980}-\x{09FF}\'\- ]{3,30})/iu', $m, $x)) return null;
        $name = trim(preg_replace('/\b(for|in|with|under|from|and|on|by)\b.*$/iu', '', $x[1]));
        if (mb_strlen($name, 'UTF-8') < 3 || BrainKnowledge::find($name, 1)) return null;
        $first = strtok(mb_strtolower($name, 'UTF-8'), ' ');
        if (in_array($first, ['reach', 'get', 'go', 'see', 'do', 'eat', 'stay', 'visit', 'travel', 'know', 'find', 'book', 'plan', 'be', 'have', 'make', 'spend', 'explore', 'pack', 'buy', 'take', 'there', 'here', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'winter', 'summer', 'monsoon', 'rainy', 'dry', 'cold', 'hot', 'day', 'days', 'people', 'persons', 'person', 'budget', 'family', 'friends', 'couple', 'weekend', 'night', 'nights', 'week', 'cheap', 'cheaper', 'luxury', 'more', 'less', 'one', 'two', 'three', 'four', 'five', 'six', 'seven'], true) || ctype_digit((string)$first)) return null;
        if (in_array(mb_strtolower($name, 'UTF-8'), ['me', 'us', 'you', 'the', 'go', 'a', 'an', 'my', 'this', 'that', 'there', 'here', 'winter', 'summer', 'monsoon', 'spring', 'autumn'], true)) return null;
        self::logUnknown($m, $name);
        $near = array_slice(array_values(BrainKnowledge::all()), 0, 4);
        return ['reply' => "I don't have a guide for \"" . mb_substr($name, 0, 40, 'UTF-8') . "\" yet, so I won't guess. I do cover " . count(BrainKnowledge::all()) . " places, for example " . BrainText::human(array_map(fn($d) => $d['name'], $near)) . ". Try the AI Trip Planner for other places, or ask me about one of these.",
            'suggestions' => ['Where should I go in winter?', 'Plan 3 days in Sylhet', 'Kolkata trip cost'], 'intent' => 'unknown_place'];
    }

    /** Append an unanswered question to data/brain/unanswered.log (JSON lines, capped) so the admin can add what people ask for. */
    public static function logUnknown(string $message, string $place = ''): void
    {
        try {
            $f = dirname(__DIR__, 2) . '/data/brain/unanswered.log';
            if (is_file($f) && filesize($f) > 400000) return;
            @file_put_contents($f, json_encode(['t' => date('c'), 'q' => mb_substr($message, 0, 300, 'UTF-8'), 'place' => $place], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) { /* logging must never break chat */ }
    }

    private static function names(int $n): string { return BrainText::human(array_slice(array_map(fn($x) => $x['name'], array_values(BrainKnowledge::all())), 0, $n)); }

    private static function chips(array $d): array
    {
        $n = $d['name'];
        return ["How to reach $n", "Best time for $n", "Plan 3 days in $n", "$n trip cost"];
    }

    private static function greeting(): array
    {
        return ['reply' => "Hello! I'm the TourBuddy Brain, a travel guide built into this site. I can plan a trip, tell you how to get somewhere, what it costs, what to eat, where to stay and when to go. Try me.",
            'suggestions' => ['Plan 3 days in Cox\'s Bazar for 4 people', 'Where should I go in winter?', 'Sylhet trip cost', 'How to reach Bandarban']];
    }

    private static function about(): array
    {
        return ['reply' => "I'm the **TourBuddy Brain**, a built-in travel expert. I run entirely inside this site: no outside AI, no API key, and I work offline. I'm not a free-form chatbot: I answer from a curated guide to " . count(BrainKnowledge::all()) . " Bangladesh destinations (routes, fares, seasons, food, stays, rules) and build full day-by-day plans. Prices are estimates.",
            'suggestions' => ['Plan a trip to Sylhet', 'Best time for Saint Martin', 'Where should I go in monsoon?']];
    }

    private static function planTrip(array $d, array $e): array
    {
        $days = $e['days'] ?? ($d['days']['ideal'] ?? 3);
        $trav = $e['travelers'] ?? 2;
        $p = BrainPlanner::plan(['destination' => $d['name'], 'duration' => $days, 'travelers' => $trav, 'budget' => $e['budget'] ?? 0,
            'start_date' => $e['month'] ? date('Y-m-d', mktime(0, 0, 0, (int)$e['month'], 10, (int)date('Y') + ((int)$e['month'] < (int)date('n') ? 1 : 0))) : date('Y-m-d', strtotime('+10 days')),
            'activities' => $e['interests'], 'accommodation' => $e['tier'] ?? '', 'origin' => $e['origin'] ?? '']);
        if (!$p) return ['reply' => "I couldn't build that plan.", 'suggestions' => []];
        $out = "**{$p['trip_title']}** for $trav " . ($trav === 1 ? 'person' : 'people') . ": about **" . BrainText::money($p['estimated_cost']['grand_total']) . "** in total (" . BrainText::money($p['estimated_cost']['grand_total'] / $trav) . " each).\n\n";
        foreach ($p['days'] as $day) {
            $stops = [];
            foreach ($day['schedule'] as $s) if (in_array($s['type'], ['sightseeing', 'activity'], true)) $stops[] = $s['activity'];
            $out .= "**Day {$day['day']}: {$day['title']}**\n" . ($stops ? implode(', ', $stops) . '.' : 'Rest and local food.') . "\n";
        }
        $out .= "\nFor the full guide with timings, costs and tips, open the **AI Trip Planner** and enter the same details. " . self::PRICE_NOTE;
        if ($p['ai_notes'] && str_contains($p['ai_notes'], 'budget')) $out .= "\n\n" . preg_replace('/ Built by.*$/s', '', $p['ai_notes']);
        return ['reply' => $out, 'total' => (int)$p['estimated_cost']['grand_total'], 'suggestions' => ["Make it cheaper", "Add a day", "How to reach {$d['name']}"]];
    }

    private static function reachAnswer(array $d, array $e): array
    {
        $o = $e['origin'] ? BrainKnowledge::find($e['origin'], 1) : [];
        $origin = $o ? BrainKnowledge::get($o[0]['id']) : null;
        $opts = BrainPlanner::reach($d, $origin);
        if (!$opts) return ['reply' => "You're already there! Ask me what to do in {$d['name']}.", 'suggestions' => self::chips($d)];
        $from = $origin['name'] ?? 'Dhaka';
        $out = "**Getting to {$d['name']} from $from**\n";
        foreach ($opts as $r) $out .= "- **{$r['mode']}**, {$r['duration']}, about " . BrainText::money($r['fare_per_person_one_way']) . " one way. {$r['operators']}. {$r['tip']}\n";
        $loc = array_map(fn($l) => $l['mode'] . ' (' . $l['group_fare'] . ')', (array)($d['local'] ?? []));
        if ($loc) $out .= "\nOnce there: " . BrainText::human($loc) . '.';
        $out .= ' ' . self::PRICE_NOTE;
        return ['reply' => trim($out), 'suggestions' => ["Best time for {$d['name']}", "Plan 3 days in {$d['name']}", "{$d['name']} trip cost"]];
    }

    private static function bestTime(array $d): array
    {
        $out = "**Best time for {$d['name']}: " . BrainKnowledge::monthsText((array)$d['best_months']) . "**\n" . ($d['season_note'] ?? '');
        if (!empty($d['avoid_note'])) $out .= "\n\n" . $d['avoid_note'];
        return ['reply' => trim($out), 'suggestions' => ["How to reach {$d['name']}", "{$d['name']} trip cost", "Plan 3 days in {$d['name']}"]];
    }

    private static function cost(array $d, array $e): array
    {
        $days = $e['days'] ?? ($d['days']['ideal'] ?? 3); $trav = $e['travelers'] ?? 2;
        $q = BrainPlanner::quote($d['name'], $days, $trav, $e['origin'] ?? null);
        if (!$q) return ['reply' => "I don't have enough data to estimate that.", 'suggestions' => []];
        $lbl = ['budget' => 'Budget', 'mid' => 'Mid-range', 'luxury' => 'Luxury'];
        $out = "**Estimated cost: $days " . ($days === 1 ? 'day' : 'days') . " in {$d['name']} for $trav " . ($trav === 1 ? 'person' : 'people') . "** (travel, stay, food, activities)\n";
        foreach ($q as $k => $v) $out .= "- {$lbl[$k]}: " . BrainText::money($v['total']) . " total, about " . BrainText::money($v['per_person']) . " per person\n";
        $out .= "\n" . self::PRICE_NOTE . " Add your budget and I'll fit the plan to it.";
        return ['reply' => $out, 'suggestions' => ["Plan $days days in {$d['name']} under " . number_format((int)round($q['mid']['total'] / 1000) * 1000), "How to reach {$d['name']}", "Where to stay in {$d['name']}"]];
    }

    private static function things(array $d): array
    {
        $out = "**Top things to do in {$d['name']}**\n";
        foreach (array_slice((array)$d['attractions'], 0, 6) as $a) $out .= "- **{$a['name']}** ({$a['area']}): " . $a['what'] . ' ' . ((int)$a['fee'] > 0 ? 'Entry about BDT ' . (int)$a['fee'] . '.' : 'Free.') . "\n";
        $out .= "\nIdeal trip length: " . ($d['days']['ideal'] ?? 3) . " days.";
        return ['reply' => trim($out), 'suggestions' => ["Plan " . ($d['days']['ideal'] ?? 3) . " days in {$d['name']}", "What to eat in {$d['name']}", "Best time for {$d['name']}"]];
    }

    private static function food(array $d): array
    {
        $out = "**What to eat in {$d['name']}**\n";
        foreach ((array)$d['food'] as $f) $out .= "- **{$f['dish']}** at {$f['where']}, about BDT " . (int)$f['cost'] . " per person\n";
        return ['reply' => trim($out), 'suggestions' => ["Where to stay in {$d['name']}", "Things to do in {$d['name']}", "{$d['name']} trip cost"]];
    }

    private static function stay(array $d): array
    {
        $out = "**Where to stay in {$d['name']}**\n";
        $any = false;
        foreach (['budget' => 'Budget', 'mid' => 'Mid-range', 'luxury' => 'Luxury'] as $k => $l) {
            $s = BrainKnowledge::stayTier($d, $k); if (!$s) continue;
            $any = true; $out .= "- **$l**: " . $s['area'] . ', BDT ' . number_format($s['price_min']) . '-' . number_format($s['price_max']) . ' per room per night. ' . ($s['note'] ?? '') . "\n";
        }
        if (!$any) $out .= "{$d['name']} works best as a day trip; stay in a nearby city.";
        else $out .= "\nSee real, bookable hotels in the **Hotels** section. " . self::PRICE_NOTE;
        return ['reply' => trim($out), 'suggestions' => ["How to reach {$d['name']}", "What to eat in {$d['name']}", "Plan 3 days in {$d['name']}"]];
    }

    private static function safety(array $d): array
    {
        $out = "**Safety and rules for {$d['name']}**\n";
        foreach ((array)($d['rules'] ?? []) as $r) $out .= "- $r\n";
        if (!empty($d['avoid_note'])) $out .= "- " . $d['avoid_note'] . "\n";
        if (!empty($d['entry_note'])) $out .= "- " . $d['entry_note'] . "\n";
        $em = $d['emergency'] ?? null;
        $out .= $em ? "\nEmergency: police **" . $em['police'] . "**, ambulance **" . $em['ambulance'] . "**. Check local conditions before you go." : "\nEmergency in Bangladesh: **999**. Health advice line: **16263**. Check local conditions before you go.";
        return ['reply' => trim($out), 'suggestions' => ["Packing list for {$d['name']}", "Best time for {$d['name']}"]];
    }

    private static function packing(array $d): array
    {
        $out = "**Packing for {$d['name']}**\n";
        foreach ((array)($d['packing'] ?? []) as $p) $out .= "- $p\n";
        return ['reply' => trim($out), 'suggestions' => ["Safety tips for {$d['name']}", "Best time for {$d['name']}"]];
    }

    private static function weather(array $d, array $e): array
    {
        $m = $e['month'] ?? (int)date('n');
        $good = in_array($m, array_map('intval', (array)$d['best_months']), true);
        $out = "**{$d['name']} in " . BrainKnowledge::monthLabel($m) . "**: " . ($good ? 'a good time to go.' : 'outside the best season.') . ' ' . ($d['season_note'] ?? '') . "\nBest months: " . BrainKnowledge::monthsText((array)$d['best_months']) . '. For a live forecast, check the weather panel when you open your trip.';
        return ['reply' => $out, 'suggestions' => ["Plan 3 days in {$d['name']}", "Packing list for {$d['name']}"]];
    }

    private static function suggest(array $e): array
    {
        $m = $e['month'] ?? (int)date('n');
        $want = $e['interests'];
        $scored = [];
        foreach (BrainKnowledge::all() as $id => $d) {
            $s = in_array($m, array_map('intval', (array)$d['best_months']), true) ? 3.0 : 0.0;
            $s += 2 * count(array_intersect((array)($d['tags'] ?? []), $want));
            if ($e['budget'] && ($d['stay']['budget']['price_min'] ?? 0) * ($e['days'] ?? 2) > $e['budget']) $s -= 1;
            $scored[$id] = $s;
        }
        arsort($scored);
        $out = "**Good picks for " . BrainKnowledge::monthLabel($m) . ($want ? ' (' . implode(', ', $want) . ')' : '') . "**\n";
        $first = null;
        foreach (array_slice(array_keys($scored), 0, 4) as $id) {
            $d = BrainKnowledge::get($id); $first ??= $d;
            $out .= "- **{$d['name']}**: " . $d['summary'] . "\n";
        }
        return ['reply' => trim($out), 'suggestions' => $first ? ["Plan 3 days in {$first['name']}", "How to reach {$first['name']}"] : []];
    }

    private static function compare(string $a, string $b, array $e): array
    {
        $A = BrainKnowledge::get($a); $B = BrainKnowledge::get($b);
        $days = $e['days'] ?? 3; $trav = $e['travelers'] ?? 2;
        $qa = BrainPlanner::quote($A['name'], $days, $trav); $qb = BrainPlanner::quote($B['name'], $days, $trav);
        $row = function (array $d, ?array $q) use ($days) {
            $hours = (float)($d['reach'][0]['hours'] ?? 0);
            return "**{$d['name']}**: best " . BrainKnowledge::monthsText((array)$d['best_months']) . "; about " . ($hours ? (int)round($hours) . ' hours from Dhaka' : 'near Dhaka') . "; ideal " . ($d['days']['ideal'] ?? 3) . " days" . ($q ? "; roughly " . BrainText::money($q['mid']['total']) . " for $days days (mid-range)" : '') . '. ' . $d['summary'];
        };
        $out = "**{$A['name']} or {$B['name']}?**\n" . $row($A, $qa) . "\n\n" . $row($B, $qb);
        $cheaper = ($qa && $qb) ? ($qa['mid']['total'] <= $qb['mid']['total'] ? $A['name'] : $B['name']) : null;
        if ($cheaper) $out .= "\n\n$cheaper is the cheaper of the two for this trip. Choose by season and the kind of trip you want.";
        return ['reply' => $out, 'suggestions' => ["Plan $days days in {$A['name']}", "Plan $days days in {$B['name']}"]];
    }
}
