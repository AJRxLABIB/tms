<?php
require_once __DIR__ . '/BrainText.php';
require_once __DIR__ . '/BrainKnowledge.php';
require_once __DIR__ . '/BrainEntities.php';

/**
 * BrainPlanner - builds a complete day-by-day trip guide WITHOUT any language model.
 *
 * An expert system over the curated knowledge base (data/brain): it picks and orders attractions by the traveller's
 * interests, group type, month and forecast, fits them into real time windows (meals, sunset, travel day, check-in/out),
 * prices everything from reference data and fits the result to the budget by trading down the least valuable costs first,
 * saying what it changed.
 *
 * The output has the same shape as the language-model planner (ItineraryPlanner), so the same validator, cost
 * recalculation, catalogue matching and page renderer work on both. All amounts are ESTIMATES in BDT.
 */
final class BrainPlanner
{
    private const SUNSET = [1 => 1055, 2 => 1080, 3 => 1095, 4 => 1105, 5 => 1115, 6 => 1125, 7 => 1125, 8 => 1110, 9 => 1085, 10 => 1060, 11 => 1040, 12 => 1040];
    private const STYLE_TAGS = ['family' => ['family', 'relaxing'], 'couple' => ['romantic', 'photography', 'relaxing'], 'solo' => ['adventure', 'culture'], 'adventure' => ['adventure', 'nature', 'hills'], 'group' => ['adventure', 'food', 'water'], 'leisure' => ['relaxing', 'nature']];
    private const MULT = ['budget' => 0.7, 'mid' => 1.0, 'luxury' => 1.7];
    private const STYLE_LABEL = ['family' => 'Family', 'couple' => 'Couple', 'solo' => 'Solo', 'adventure' => 'Adventure', 'group' => 'Group', 'leisure' => 'Leisure'];

    public static function hhmm(int $m): string { $m = max(0, min(1439, $m)); return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }

    /** Destination record for free text, or null. */
    public static function resolve(string $text): ?array
    {
        $f = BrainKnowledge::find($text, 1);
        return $f ? BrainKnowledge::get($f[0]['id']) : null;
    }

    private static function km(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $p1 = deg2rad($la1); $p2 = deg2rad($la2); $dp = deg2rad($la2 - $la1); $dl = deg2rad($lo2 - $lo1);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
    }

    /**
     * Ways to reach $dest from $origin (destination record, or null = Dhaka). Knowledge-base entries when the origin is
     * Dhaka; otherwise a labelled estimate from straight-line distance.
     * @return array<int,array<string,mixed>>
     */
    public static function reach(array $dest, ?array $origin): array
    {
        $g = BrainKnowledge::global();
        $out = [];
        $oName = $origin['name'] ?? 'Dhaka';
        if ($origin && $origin['id'] === $dest['id']) return [];
        if (!$origin || $origin['id'] === 'dhaka') {
            foreach ((array)($dest['reach'] ?? []) as $r) {
                $h = (float)($r['hours'] ?? 0);
                $mid = (int)round((($r['fare_min'] ?? 0) + ($r['fare_max'] ?? 0)) / 2);
                $out[] = ['mode' => (string)$r['mode'], 'route' => $oName . ' to ' . $dest['name'], 'operators' => (string)$r['how'],
                    'departure_tip' => $h >= 7 ? 'Night departures arrive early morning' : ($h <= 2 ? 'Morning flights leave the afternoon free' : 'Leave in the morning'),
                    'duration' => $h <= 1.5 ? 'about 1 hour' : 'about ' . (int)round($h) . ' hours', 'hours' => $h,
                    'fare_per_person_one_way' => $mid, 'comfort' => $mid > 3000 ? 'Premium' : ($mid > 1200 ? 'Comfort' : 'Economy'),
                    'tip' => (string)($r['note'] ?? ''), 'estimated' => false];
            }
            return $out;
        }
        $km = self::km((float)$origin['lat'], (float)$origin['lng'], (float)$dest['lat'], (float)$dest['lng']) * (float)($g['road_factor'] ?? 1.35);
        $h = max(1.0, $km / (float)($g['bus_kmh'] ?? 42));
        $rate = is_array($g['bus_fare_per_km'] ?? null) ? (float)($g['bus_fare_per_km']['mid'] ?? 3.0) : (float)($g['bus_fare_per_km'] ?? 3.0);
        $fare = (int)(round($km * $rate / 50) * 50);
        $out[] = ['mode' => 'Bus or train', 'route' => $oName . ' to ' . $dest['name'], 'operators' => 'Intercity bus or train: check Bangladesh Railway e-ticket and bus counters for current operators and times',
            'departure_tip' => $h >= 7 ? 'A night departure saves a day' : 'Leave in the morning', 'duration' => 'about ' . (int)round($h) . ' hours', 'hours' => $h,
            'fare_per_person_one_way' => max(150, $fare), 'comfort' => 'Economy',
            'tip' => 'Estimated from the straight-line distance (about ' . (int)round($km) . ' km by road). Check the real fare before you book.', 'estimated' => true];
        return $out;
    }

    /**
     * @param array $trip destination, duration, start_date, travelers, budget, travel_type, activities, accommodation, special_requests, origin
     * @param array $opts weather => [date => ['rain_pct'=>int]], catalogue => rows with kind/name/price
     * @return array|null null when the destination is not in the knowledge base
     */
    public static function plan(array $trip, array $opts = []): ?array
    {
        $dest = self::resolve((string)($trip['destination'] ?? ''));
        if (!$dest) return null;
        $g = BrainKnowledge::global();

        $days = max(1, min(14, (int)($trip['duration'] ?? 3)));
        $trav = max(1, min(50, (int)($trip['travelers'] ?? 2)));
        $budget = max(0, (int)($trip['budget'] ?? 0));
        $style = strtolower((string)($trip['travel_type'] ?? 'leisure')); if (!isset(self::STYLE_TAGS[$style])) $style = 'leisure';
        $start = (!empty($trip['start_date']) && strtotime((string)$trip['start_date'])) ? strtotime((string)$trip['start_date']) : strtotime('+7 days');

        $actText = is_array($trip['activities'] ?? null) ? implode(' ', $trip['activities']) : (string)($trip['activities'] ?? '');
        $ent = BrainEntities::parse($actText . ' ' . (string)($trip['special_requests'] ?? ''));
        $explicit = $ent['interests'];
        $interests = array_values(array_unique(array_merge($explicit, self::STYLE_TAGS[$style])));

        $originRec = null; $notes = [];
        $oText = trim((string)($trip['origin'] ?? ''));
        if ($oText !== '') {
            $of = BrainKnowledge::find($oText, 1);
            if ($of) $originRec = BrainKnowledge::get($of[0]['id']);
            else $notes[] = 'We did not recognise the starting city "' . mb_substr($oText, 0, 40) . '", so the journey is planned from Dhaka.';
        }
        $reach = self::reach($dest, $originRec);

        $tierMap = $g['tier_from_accommodation'] ?? [];
        $acc = strtolower((string)($trip['accommodation'] ?? ''));
        $tierReq = ($acc !== '' && isset($tierMap[$acc])) ? $tierMap[$acc] : ($ent['tier'] ?? null);
        $tier = $tierReq ?? ($g['tier_from_style'][$style] ?? 'mid');
        if ($tierReq === null && $budget > 0) {
            $perDay = $budget / $trav / $days;
            $tier = $perDay < 3200 ? 'budget' : ($perDay < 7500 ? 'mid' : 'luxury');
        }
        $dayTrip = !BrainKnowledge::stayTier($dest, 'budget') && !BrainKnowledge::stayTier($dest, 'mid');

        $lower = ['luxury' => 'mid', 'mid' => 'budget', 'budget' => 'budget'];
        $attempts = [['tier' => $tier, 'meal' => $tier, 'cheap' => false, 'drop' => 0.0]];
        if ($budget > 0) {
            $attempts[] = ['tier' => $tier, 'meal' => $lower[$tier], 'cheap' => true, 'drop' => 0.0];
            $attempts[] = ['tier' => $lower[$tier], 'meal' => $lower[$tier], 'cheap' => true, 'drop' => 0.0];
            $attempts[] = ['tier' => 'budget', 'meal' => 'budget', 'cheap' => true, 'drop' => 0.0];
            $attempts[] = ['tier' => 'budget', 'meal' => 'budget', 'cheap' => true, 'drop' => 0.34];
            $attempts[] = ['tier' => 'budget', 'meal' => 'budget', 'cheap' => true, 'drop' => 0.67];
        }
        $built = null; $first = null; $used = $attempts[0]; $floor = null; $floorA = null;
        foreach ($attempts as $i => $a) {
            $b = self::build($dest, $originRec, $reach, $days, $trav, $start, $style, $interests, $explicit, $a, $dayTrip, $opts);
            if ($i === 0) $first = $b;
            if ($i === 3) { $floor = $b; $floorA = $a; }
            $built = $b; $used = $a;
            if ($budget <= 0 || $b['internal_total'] <= $budget) break;
        }
        // never fits: keep the cheapest version that still has a full programme, not an emptied one
        if ($budget > 0 && $built['internal_total'] > $budget && $floor) { $built = $floor; $used = $floorA; }
        if ($budget > 0 && $first && $built) {
            if ($built['internal_total'] <= $budget && $used !== $attempts[0]) {
                $parts = [];
                if ($used['tier'] !== $tier) $parts[] = 'stays in ' . $used['tier'] . ' hotels instead of ' . $tier . ' ones';
                if ($used['meal'] !== $tier) $parts[] = 'chooses ' . $used['meal'] . ' meals';
                if ($used['cheap']) $parts[] = 'takes the cheapest way to get there';
                if ($used['drop'] > 0) $parts[] = 'skips the most expensive paid activities';
                $notes[] = 'To fit your budget of ' . BrainText::money($budget) . ' (the first draft came to about ' . BrainText::money($first['internal_total']) . ') this plan ' . BrainText::human($parts) . '.';
            } elseif ($built['internal_total'] > $budget) {
                $notes[] = 'Even the cheapest version of this plan comes to about ' . BrainText::money($built['internal_total']) . ', above your budget of ' . BrainText::money($budget) . '. Consider fewer days, fewer rooms, or a cheaper month.';
            }
        }

        $plan = $built['plan'];
        $ideal = (int)($dest['days']['ideal'] ?? 3);
        if ($days > $ideal + 1) $notes[] = $dest['name'] . ' is usually seen in about ' . $ideal . ' day' . ($ideal === 1 ? '' : 's') . '; the extra days use nearby places and slower pacing. Consider combining it with a nearby destination.';
        $plan['ai_notes'] = trim(implode(' ', $notes) . ' Built by the TourBuddy Brain, our offline travel engine (no outside AI). Prices are approximate reference ranges, and fares and entry fees change: confirm before you pay.'
            . (!empty($dest['avoid_note']) ? ' Note: ' . $dest['avoid_note'] : ''));
        $plan['engine'] = 'brain';
        return $plan;
    }

    /** @return array{plan:array,internal_total:int} */
    private static function build(array $dest, ?array $originRec, array $reach, int $days, int $trav, int $start, string $style, array $interests, array $explicit, array $a, bool $dayTrip, array $opts): array
    {
        $g = BrainKnowledge::global();
        $tier = $a['tier']; $mealTier = $a['meal'];
        $rooms = max(1, (int)ceil($trav / 2));
        $month = (int)date('n', $start);
        $weather = is_array($opts['weather'] ?? null) ? $opts['weather'] : [];
        $localList = (array)($dest['local'] ?? []);
        $hopFare = 0; $hopMode = 'Local transport'; $hireFare = 0; $hireMode = '';
        foreach ($localList as $l) {
            $f = BrainText::fare((string)($l['group_fare'] ?? ''));
            if ($f <= 0) continue;
            if ($f <= 700 && $hopFare === 0) { $hopFare = $f; $hopMode = (string)$l['mode']; }
            elseif ($f > 700 && $hireFare === 0) { $hireFare = $f; $hireMode = (string)$l['mode']; }
        }
        if ($hopFare === 0 && $hireFare === 0) $hopFare = 250;
        $useHire = $hopFare === 0;   // no cheap local mode here (hills, islands): one daily hire covers the day's stops
        $oName = $originRec['name'] ?? 'Dhaka';
        $hotelName = null; $hotelArea = ''; $nightPrice = 0;
        if (!$dayTrip) {
            $st = BrainKnowledge::stayTier($dest, $tier) ?? BrainKnowledge::stayTier($dest, 'mid') ?? BrainKnowledge::stayTier($dest, 'budget');
            if ($st) { $nightPrice = (int)(round((($st['price_min'] + $st['price_max']) / 2) / 50) * 50); $hotelArea = (string)$st['area']; }
            foreach ((array)($opts['catalogue'] ?? []) as $r) {
                if (($r['kind'] ?? '') !== 'hotel' || empty($r['name']) || !isset($r['price']) || !$st) continue;
                if ($r['price'] >= $st['price_min'] * 0.7 && $r['price'] <= $st['price_max'] * 1.3) { $hotelName = (string)$r['name']; $nightPrice = (int)$r['price']; break; }
            }
        }

        $chosen = null;
        if ($reach) {
            if ($a['cheap']) { $byFare = $reach; usort($byFare, fn($x, $y) => $x['fare_per_person_one_way'] <=> $y['fare_per_person_one_way']); $chosen = $byFare[0]; }
            else {
                $bestScore = INF; $valueOfHour = $tier === 'luxury' ? 700 : ($tier === 'mid' ? 250 : 90);
                foreach ($reach as $r) { $s = $r['fare_per_person_one_way'] + $r['hours'] * $valueOfHour; if ($s < $bestScore) { $bestScore = $s; $chosen = $r; } }
            }
        }
        $H = $chosen ? (float)$chosen['hours'] : 0.0;
        $journeyCost = $chosen ? (int)($chosen['fare_per_person_one_way'] * 2 * $trav) : 0;
        $sunset = self::SUNSET[$month] ?? 1080;

        $attr = (array)($dest['attractions'] ?? []);
        $mainCount = count($attr);
        if ($days >= 3) {   // long stay: add day trips to nearby places from the knowledge base
            foreach ((array)($dest['nearby'] ?? []) as $nid) {
                $nd = BrainKnowledge::get((string)$nid);
                if (!$nd || $nd['id'] === $dest['id']) continue;
                foreach (array_slice((array)$nd['attractions'], 0, 3) as $x) {
                    $x['id'] = 'n_' . $nid . '_' . $x['id']; $x['area'] = 'Day trip to ' . $nd['name'];
                    $x['mins'] = max(300, (int)$x['mins'] + 120);
                    $x['tip'] = 'Day trip from ' . $dest['name'] . ': allow time for the journey. ' . $x['tip'];
                    $attr[] = $x;
                }
            }
        }
        $n = max(1, count($attr));
        $usedIds = []; $dayRows = [];
        $dropIds = [];
        if ($a['drop'] > 0) {
            $paid = array_values(array_filter($attr, fn($x) => ($x['fee'] ?? 0) > 0)); usort($paid, fn($x, $y) => $y['fee'] <=> $x['fee']);
            foreach (array_slice($paid, 0, (int)ceil(count($paid) * $a['drop'])) as $x) $dropIds[$x['id']] = true;
        }
        $mealMult = self::MULT[$mealTier] ?? 1.0;
        $foods = array_values((array)($dest['food'] ?? [])); $fi = 0;
        $mealBase = $g['meals'][$mealTier] ?? ['breakfast' => 150, 'lunch' => 250, 'dinner' => 300];

        for ($d = 1; $d <= $days; $d++) {
            $date = date('Y-m-d', strtotime('+' . ($d - 1) . ' day', $start));
            $rain = isset($weather[$date]['rain_pct']) ? (int)$weather[$date]['rain_pct'] : null;
            $wet = $rain !== null ? $rain >= 60 : in_array($month, [6, 7, 8, 9], true);
            $dayStart = 8 * 60 + 30; $dayEnd = 21 * 60; $first = $d === 1; $last = $d === $days;
            $sched = []; $breakfastOk = true;

            if ($first && $chosen) {
                if ($H >= 7) { $sched[] = self::travelRow($H, $chosen, 7 * 60 + 30, true, $oName, $dest['name'], 'go'); $dayStart = 10 * 60; }
                elseif ($H > 2.5) { $sched[] = self::travelRow($H, $chosen, 7 * 60, false, $oName, $dest['name'], 'go'); $dayStart = (int)(7 * 60 + $H * 60 + 60); $breakfastOk = false; }
                else { $sched[] = self::travelRow($H, $chosen, 8 * 60, false, $oName, $dest['name'], 'go'); $dayStart = (int)(8 * 60 + $H * 60 + 90); $breakfastOk = false; }
            }
            if ($last && $chosen) $dayEnd = $days === 1 ? ($H >= 7 ? 17 * 60 : 16 * 60) : ($H >= 7 ? 19 * 60 + 30 : ($H > 2.5 ? 15 * 60 : 14 * 60 + 30));

            if ($first && !$dayTrip && $days > 1) {
                $ci = ($chosen && $H >= 7) ? 8 * 60 + 15 : max($dayStart - 30, 9 * 60);
                $sched[] = self::row($ci, 'accommodation', 'Check in and freshen up', $hotelName ?? ('Your hotel in ' . ($hotelArea ?: $dest['name'])), $hotelArea,
                    'Drop your bags, wash up and rest a little. Early check-in is not guaranteed, so ask the hotel to keep your luggage if the room is not ready.', 45, 0, 'No extra cost', null, 'Ask for the check-in time when you book.', null, '');
            }

            $wins = [];
            $w1 = [max($dayStart, 9 * 60), 12 * 60 + 45, 'morning']; if ($w1[1] - $w1[0] >= 50) $wins[] = $w1;
            $w2 = [max($dayStart, 14 * 60 + 15), min($dayEnd - 30, $sunset - 55), 'afternoon']; if ($w2[1] - $w2[0] >= 50) $wins[] = $w2;
            $w3 = [max($dayStart, $sunset - 50), min($dayEnd, 19 * 60), 'sunset']; if ($w3[1] - $w3[0] >= 45) $wins[] = $w3;

            $hireCharged = false; $dayAreas = []; $dayAtt = []; $prevLoc = $first && $dayTrip ? 'the arrival point' : 'your hotel'; $fullDay = false;
            foreach ($wins as $w) {
                if ($fullDay && $w[2] !== 'sunset') continue;
                $len = $w[1] - $w[0];
                $best = null; $bestScore = -INF;
                foreach ($attr as $i => $at) {
                    if (isset($usedIds[$at['id']]) || isset($dropIds[$at['id']])) continue;
                    $mins = (int)$at['mins']; $isFull = $mins >= 300;
                    if ($isFull && ($w[2] !== 'morning' || $days < 3 || $dayEnd < 18 * 60)) continue;
                    if (!$isFull && $mins > $len + 25) continue;
                    $tags = (array)($at['tags'] ?? []);
                    $score = 1.0 + 2.0 * (($n - $i) / $n) + 2.5 * count(array_intersect($tags, $explicit)) + 1.2 * count(array_intersect($tags, $interests));
                    $b = (string)($at['best'] ?? 'any');
                    if ($b === $w[2]) $score += 2.0; elseif ($b !== 'any') $score -= 2.5;
                    if ($wet) $score += !empty($at['indoor']) ? 2.5 : -2.0;
                    $area = (string)($at['area'] ?? '');
                    if ($area !== '' && in_array($area, $dayAreas, true)) $score += 1.5;
                    if ($isFull) $score -= 1.5;
                    if ($i >= $mainCount) { foreach ($attr as $j => $o) { if ($j < $mainCount && !isset($usedIds[$o['id']]) && !isset($dropIds[$o['id']])) { $score -= 4; break; } } }
                    if ($score > $bestScore) { $bestScore = $score; $best = [$at, $isFull]; }
                }
                if (!$best) continue;
                [$at, $isFull] = $best;
                $usedIds[$at['id']] = true;
                $same = in_array($at['area'] ?? '', $dayAreas, true);
                $dayAreas[] = (string)($at['area'] ?? ''); $dayAtt[] = $at;
                $mins = (int)$at['mins']; $t0 = $w[0];
                if ($isFull) { $fullDay = true; $t0 = max($dayStart, 8 * 60); $mins = max(120, min($mins, 13 * 60 - $t0)); }
                else $mins = min($mins, $len);
                if ($useHire) {
                    $hop = ['from' => $prevLoc, 'mode' => $hireMode . ($hireCharged ? ' (same hire)' : ' (hire for the day)'), 'duration' => $same ? '10 min' : '30-60 min', 'cost_group' => $hireCharged ? 0 : $hireFare];
                    $hireCharged = true;
                } else {
                    $hopCost = ($isFull || $mins >= 240) ? (int)round($hopFare * 1.8) : $hopFare;
                    $hop = ['from' => $prevLoc, 'mode' => $hopMode, 'duration' => $same ? '10 min' : ($isFull ? '1 hour or more' : '20-30 min'), 'cost_group' => (int)round($hopCost * ($same ? 0.6 : 1))];
                }
                $fee = (int)($at['fee'] ?? 0);
                $what = (string)$at['what'] . ($isFull ? ' This is a full-day trip (about ' . round($at['mins'] / 60, 1) . ' hours including the trek or boat), so take a packed meal or eat at the site.' : '');
                $alt = null;
                if ($wet && empty($at['indoor'])) foreach ($attr as $o) { if (!empty($o['indoor']) && !isset($usedIds[$o['id']])) { $alt = 'If it rains: ' . $o['name'] . ' (' . ($o['area'] ?? '') . ') is under cover.'; break; } }
                $tg = (array)$at['tags'];
                $type = (array_intersect($tg, ['adventure', 'water', 'trekking']) ? 'activity' : 'sightseeing');
                $sched[] = self::row($t0, $type, (string)$at['name'], (string)$at['name'], (string)($at['area'] ?? ''), $what, $mins, $fee,
                    $fee > 0 ? 'About BDT ' . $fee . ' per person (entry or ticket, approximate)' : 'Free', $hop, (string)$at['tip'], $alt, $fee > 0 ? 'Pay at the entrance or through your guide.' : 'No ticket needed.');
                $prevLoc = (string)$at['name'];
            }

            $meals = [];
            if ($breakfastOk && !($first && $dayTrip && $H <= 2.5)) $meals[] = ['breakfast', ($first && $chosen && $H >= 7) ? 8 * 60 + 40 : 8 * 60];
            if ($fullDay) $meals[] = ['packed', 13 * 60];
            elseif ($dayEnd >= 14 * 60 && $dayStart <= 13 * 60 + 30) $meals[] = ['lunch', 13 * 60];
            if ($dayEnd >= 18 * 60 + 30) $meals[] = ['dinner', min(19 * 60 + 40, $dayEnd - 60)];
            $mealDetails = [];
            foreach ($meals as [$kind, $t]) {
                if ($kind === 'breakfast') {
                    $c = (int)($mealBase['breakfast'] ?? 150);
                    $place = ($first && $dayTrip) ? 'A local cafe' : 'Your hotel or a nearby cafe';
                    $mealDetails['breakfast'] = ['place' => $place, 'dish' => 'Local breakfast (paratha, egg, tea or the hotel buffet)', 'cost_per_person' => $c];
                    $sched[] = self::row($t, 'meal', 'Breakfast', $place, $hotelArea, 'A proper breakfast before the day: paratha with egg or dal, and tea.', 45, $c, 'Per person, approximate', null, 'Eat early to avoid queues at popular spots.', null, '');
                } elseif ($kind === 'packed') {
                    $c = (int)round(($mealBase['lunch'] ?? 200) * 0.7);
                    $mealDetails['lunch'] = ['place' => 'On the way', 'dish' => 'Packed lunch', 'cost_per_person' => $c];
                    $sched[] = self::row($t, 'meal', 'Packed lunch on the trip', 'On the way', '', 'Eat the lunch you packed, or at a simple stall near the site.', 40, $c, 'Per person, approximate', null, 'Carry water and snacks.', null, '');
                } else {
                    $f = $foods ? $foods[$fi % count($foods)] : ['dish' => 'Local specialities', 'where' => $dest['name'], 'cost' => 250];
                    $fi++;
                    $c = (int)(round(((int)($f['cost'] ?? 300)) * $mealMult / 10) * 10);
                    $c = max($c, (int)round(($mealBase[$kind] ?? 200) * 0.6));
                    $mealDetails[$kind] = ['place' => (string)$f['where'], 'dish' => (string)$f['dish'], 'cost_per_person' => $c];
                    $sched[] = self::row($t, 'meal', ucfirst($kind) . ': ' . $f['dish'], (string)$f['where'], '', 'Order ' . strtolower((string)$f['dish']) . ' and ask for the daily special.', $kind === 'dinner' ? 75 : 60, $c, 'Per person, approximate', null, 'Ask the price before ordering seafood or by-weight dishes.', null, '');
                }
            }

            $stay = null;
            if (!$dayTrip && !$last) {
                $groupNight = $nightPrice * $rooms;
                $sched[] = self::row(21 * 60, 'accommodation', 'Overnight stay' . ($hotelName ? ': ' . $hotelName : ''), $hotelName ?? ('A ' . $tier . ' hotel in ' . ($hotelArea ?: $dest['name'])), $hotelArea,
                    'Sleep at a ' . $tier . ' hotel in ' . ($hotelArea ?: $dest['name']) . '. Rooms typically cost about ' . BrainText::money($nightPrice) . ' per night, and you need ' . $rooms . ' room' . ($rooms > 1 ? 's' : '') . '.', 0, $groupNight, 'Total for the group for the night, approximate', null, 'Book early on weekends and public holidays.', null, '');
                $stay = ['name' => $hotelName ?? ('A ' . $tier . ' hotel'), 'area' => $hotelArea ?: $dest['name'], 'price_group_per_night' => $groupNight, 'booking_tip' => 'Book early on weekends and public holidays.'];
            }
            if ($last && !$dayTrip && $days > 1) {
                $sched[] = self::row(8 * 60 + 15, 'accommodation', 'Check out', $hotelName ?? 'Your hotel', $hotelArea, 'Pay any extras and collect your bags; leave them at reception if you still have hours to spare.', 20, 0, 'No extra cost', null, 'Ask for a receipt for any extras.', null, '');
            }
            if ($last && $chosen) {
                $dT = $days === 1 ? ($H >= 7 ? 17 * 60 + 30 : 16 * 60 + 30) : ($H >= 7 ? 19 * 60 + 30 : ($H > 2.5 ? 15 * 60 : 15 * 60 + 30));
                $sched[] = self::travelRow($H, $chosen, $dT, $H >= 7, $dest['name'], $oName, 'back');
            }

            usort($sched, fn($x, $y) => $x['_t'] <=> $y['_t']);
            foreach ($sched as &$r) { $r['time'] = self::hhmm($r['_t']); unset($r['_t']); }
            unset($r);

            $title = $first && $chosen ? 'Arrival and first sights' : ($last && $days > 1 ? 'Last morning and journey home' : ($dayAtt ? self::dayTitle($dayAtt) : 'Free day'));
            $wn = $rain !== null ? "Forecast: about {$rain}% chance of rain." . ($wet ? ' Carry an umbrella; covered places are preferred today.' : ' Good conditions for outdoor stops.') : self::seasonLine($dest, $month);
            $dayRows[] = [
                'day' => $d, 'date' => $date, 'title' => $title,
                'overview' => $dayAtt ? 'Today: ' . BrainText::human(array_map(fn($x) => $x['name'], $dayAtt)) . '. Stops are grouped by area to keep travel short.' : 'A lighter day: rest, eat well and explore at your own pace.',
                'area' => $dayAreas ? implode(', ', array_slice(array_values(array_unique(array_filter($dayAreas))), 0, 2)) : (string)$dest['name'],
                'weather_note' => $wn, 'schedule' => $sched, 'meal_suggestions' => $mealDetails, 'stay' => $stay,
                'daily_budget' => 0, 'pro_tip' => $dayAtt ? (string)$dayAtt[0]['tip'] : 'Keep some free time: the best moments are often unplanned.',
            ];
        }

        $acc = 0; $food = 0; $act = 0; $hops = 0;
        foreach ($dayRows as $dr) foreach ($dr['schedule'] as $r) {
            if ($r['type'] === 'accommodation') $acc += (int)$r['cost'];
            elseif ($r['type'] === 'meal') $food += (int)$r['cost'] * $trav;
            elseif ($r['type'] !== 'transport') $act += (int)$r['cost'] * $trav;
            if (!empty($r['how_to_get_there']['cost_group'])) $hops += (int)$r['how_to_get_there']['cost_group'];
        }
        $core = $acc + $food + $act + $hops + $journeyCost;
        $misc = (int)round($core * 0.07);

        $plan = self::wrap($dest, $reach, $chosen, $dayRows, $days, $trav, $tier, $style, $interests, $usedIds, $attr, $nightPrice, $rooms, $month, $hotelName);
        // 'total' = the costs the day rows carry (the shared pipeline adds local hops, the journey and the 7% buffer); 'grand_total' = everything.
        $plan['estimated_cost'] = ['accommodation' => $acc, 'transport' => 0, 'food' => $food, 'activities' => $act, 'miscellaneous' => 0, 'total' => $acc + $food + $act,
            'grand_total' => $core + $misc, 'local_hops' => $hops, 'journey' => $journeyCost];
        return ['plan' => $plan, 'internal_total' => $core + $misc];
    }

    private static function row(int $t, string $type, string $activity, string $location, string $area, string $what, int $mins, int $cost, string $costNote, ?array $hop, ?string $tip, ?string $alt, string $booking): array
    {
        return ['_t' => $t, 'type' => $type, 'activity' => $activity, 'location' => $location, 'area' => $area, 'what_to_do' => $what,
            'duration' => ($mins >= 60 && $mins % 60 === 0) ? ($mins / 60) . ' hours' : $mins . ' min', 'cost' => $cost, 'cost_note' => $costNote,
            'how_to_get_there' => $hop, 'booking' => $booking, 'tip' => (string)$tip, 'alternative' => $alt, 'notes' => ''];
    }

    private static function travelRow(float $h, array $opt, int $t, bool $overnight, string $from, string $to, string $dir): array
    {
        $mode = strtolower($opt['mode']);
        if ($dir === 'go') {
            $title = 'Travel to ' . $to;
            $what = $overnight ? 'Arrive early this morning. Take the night ' . $mode . ' the evening before (' . $opt['duration'] . ').' : 'Travel by ' . $mode . ' (' . $opt['duration'] . ').';
            $loc = $to;
        } else {
            $title = 'Travel home to ' . $to;
            $what = 'Head back by ' . $mode . ' (' . $opt['duration'] . '). ' . ($overnight ? 'Night coaches usually leave in the evening and arrive in the morning.' : 'Leave in time to reach the station or airport comfortably.');
            $loc = $from;
        }
        return self::row($t, 'transport', $title, $loc, '', $what . ' ' . $opt['operators'] . '.', $overnight ? ($dir === 'go' ? 30 : 120) : (int)round($h * 60), 0,
            'About ' . BrainText::money($opt['fare_per_person_one_way']) . ' per person each way (counted in the journey cost)', null, (string)$opt['tip'], null, 'Book tickets early, especially at weekends and holidays.');
    }

    private static function dayTitle(array $atts): string
    {
        $tags = [];
        foreach ($atts as $a) foreach ((array)$a['tags'] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
        arsort($tags); $top = (string)array_key_first($tags);
        $label = ['beach' => 'Beach day', 'hills' => 'Hills and views', 'nature' => 'Nature day', 'history' => 'History and heritage', 'culture' => 'Culture and local life', 'food' => 'Food day', 'water' => 'On the water', 'adventure' => 'Adventure day', 'tea' => 'Tea gardens', 'wildlife' => 'Wildlife day', 'spiritual' => 'Spiritual sites', 'city' => 'City day', 'photography' => 'Views and photography', 'relaxing' => 'Slow and easy day'];
        return ($label[$top] ?? 'Exploration day') . ': ' . $atts[0]['name'];
    }

    private static function seasonLine(array $dest, int $month): string
    {
        $good = in_array($month, array_map('intval', (array)($dest['best_months'] ?? [])), true);
        return ($good ? 'This is a good time of year for ' . $dest['name'] . '. ' : 'This is outside the best season for ' . $dest['name'] . '. ') . (string)($dest['season_note'] ?? '');
    }

    private static function wrap(array $dest, array $reach, ?array $chosen, array $dayRows, int $days, int $trav, string $tier, string $style, array $interests, array $usedIds, array $attr, int $nightPrice, int $rooms, int $month, ?string $hotelName): array
    {
        $good = in_array($month, array_map('intval', (array)($dest['best_months'] ?? [])), true);
        $unused = array_values(array_filter($attr, fn($x) => !isset($usedIds[$x['id']])));
        $gems = array_map(fn($x) => $x['name'] . ' (' . ($x['area'] ?? $dest['name']) . '): ' . $x['tip'], array_slice($unused, 0, 3));

        $stays = [];
        foreach (['budget' => 'Budget', 'mid' => 'Mid-range', 'luxury' => 'Luxury'] as $k => $label) {
            $s = BrainKnowledge::stayTier($dest, $k); if (!$s) continue;
            $stays[] = ['name' => $label . ' stays in ' . $s['area'], 'tier' => $label, 'area' => (string)$s['area'], 'price_range' => 'BDT ' . number_format($s['price_min']) . '-' . number_format($s['price_max']) . ' per room per night', 'why' => (string)($s['note'] ?? 'Typical range from our reference data. Compare real hotels on TourBuddy before booking.')];
        }
        if ($hotelName) array_unshift($stays, ['name' => $hotelName, 'tier' => 'On TourBuddy', 'area' => '', 'price_range' => 'BDT ' . number_format($nightPrice) . ' per room per night', 'why' => 'From the TourBuddy catalogue, so it can be booked on this site.']);
        $rests = array_map(fn($f) => ['name' => (string)$f['dish'], 'area' => (string)$f['where'], 'cuisine' => 'Local', 'price_range' => 'About BDT ' . (int)$f['cost'] . ' per person', 'must_try' => (string)$f['dish']], array_slice((array)($dest['food'] ?? []), 0, 4));

        $busFare = null; $flyFare = null;
        foreach ($reach as $r) {
            if (stripos($r['mode'], 'bus') !== false && $busFare === null) $busFare = $r['fare_per_person_one_way'];
            if (stripos($r['mode'], 'flight') !== false) $flyFare = $r['fare_per_person_one_way'];
        }
        $save = [
            'Travel on a weekday: hotels, jeeps and boats cost less and sights are quieter.',
            'Share local transport (CNG, jeep or boat) among your ' . $trav . ' ' . ($trav === 1 ? 'traveller' : 'travellers') . ' and agree the fare before you start.',
            'Eat where locals eat, and ask the price before ordering seafood or anything sold by weight.',
        ];
        if ($busFare && $flyFare && $flyFare > $busFare) array_unshift($save, 'Going by road instead of flying saves about ' . BrainText::money(($flyFare - $busFare) * 2 * $trav) . ' return for ' . $trav . ' people.');
        $bud = BrainKnowledge::stayTier($dest, 'budget');
        if ($tier !== 'budget' && $bud && $nightPrice > $bud['price_min'] && $days > 1) $save[] = 'Switching to budget hotels could save roughly ' . BrainText::money(($nightPrice - (int)$bud['price_min']) * $rooms * ($days - 1)) . ' over this trip.';

        $before = [];
        if ($chosen) $before[] = 'Book your ' . strtolower($chosen['mode']) . ' tickets early; seats sell out at weekends and holidays.';
        if (!empty($dest['entry_note'])) array_unshift($before, (string)$dest['entry_note']);
        if (!empty($dest['avoid_note'])) $before[] = (string)$dest['avoid_note'];
        $tagsAll = [];
        foreach ($attr as $x) foreach ((array)$x['tags'] as $t) $tagsAll[$t] = true;
        $remote = (bool)array_intersect(['hills', 'adventure', 'wildlife'], array_keys($tagsAll));
        $before[] = 'Carry enough cash' . ($remote ? ': ATMs and card machines are rare at remote spots.' : ' for small shops, boats and local transport.');
        $before[] = 'Check the weather forecast two days before you leave and keep hotel and ticket confirmations on your phone.';

        $lean = array_slice($interests, 0, 3);
        $overview = 'A ' . $days . '-day ' . strtolower(self::STYLE_LABEL[$style]) . ' trip to ' . $dest['name'] . ' for ' . $trav . ' ' . ($trav === 1 ? 'traveller' : 'travellers') . '. ' . $dest['summary']
            . ($lean ? ' The plan leans towards ' . BrainText::human($lean) . ' and groups stops by area to keep travel short.' : '');

        return [
            'trip_title' => $days . '-Day ' . self::STYLE_LABEL[$style] . ' Trip to ' . $dest['name'],
            'destination' => (string)$dest['name'], 'total_duration' => $days, 'overview' => $overview,
            'best_visiting_time' => BrainKnowledge::monthsText((array)($dest['best_months'] ?? [])) . '. ' . (string)($dest['season_note'] ?? ''),
            'weather_risk_analysis' => ['summary' => (string)($dest['season_note'] ?? ''), 'risk_level' => $good ? 'low' : 'moderate', 'contingency_plan' => 'Keep one covered or indoor stop ready for each day and move outdoor stops to the driest part of the day.'],
            'getting_there' => array_map(function ($r) use ($chosen) { unset($r['hours']); $r['recommended'] = $chosen && $r['mode'] === $chosen['mode'] && $r['operators'] === $chosen['operators']; return $r; }, $reach),
            'days' => $dayRows, 'hotel_recommendations' => $stays, 'restaurant_recommendations' => $rests,
            'transportation_suggestions' => array_map(fn($l) => $l['mode'] . ': about ' . $l['group_fare'] . '. ' . ($l['note'] ?? ''), (array)($dest['local'] ?? [])),
            'before_you_go' => $before, 'packing_essentials' => (array)($dest['packing'] ?? []),
            'emergency_info' => ['local_police' => (string)($dest['emergency']['police'] ?? '999 (national emergency)'), 'ambulance' => (string)($dest['emergency']['ambulance'] ?? '999, or 16263 for health advice'), 'nearest_hospital' => 'The district or Sadar hospital in ' . $dest['name'] . ' (ask your hotel for the nearest one)', 'embassy_if_abroad' => 'not applicable'],
            'safety_tips' => (array)($dest['rules'] ?? []), 'money_saving_tips' => $save, 'hidden_gems' => $gems,
        ];
    }

    /** Price check for the chat: totals for each stay level. @return array<string,array{total:int,per_person:int}>|null */
    public static function quote(string $destText, int $days, int $trav, ?string $originText = null): ?array
    {
        $out = [];
        foreach (['budget', 'mid', 'luxury'] as $t) {
            $p = self::plan(['destination' => $destText, 'duration' => $days, 'travelers' => $trav, 'accommodation' => $t === 'mid' ? 'hotel' : $t, 'origin' => $originText ?? '', 'start_date' => date('Y-m-d', strtotime('+14 days')), 'travel_type' => 'leisure']);
            if (!$p) return null;
            $tot = (int)($p['estimated_cost']['grand_total'] ?? 0);
            $out[$t] = ['total' => $tot, 'per_person' => (int)round($tot / $trav)];
        }
        return $out;
    }
}
