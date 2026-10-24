<?php
/** Checks one destination record (decoded JSON) against the Brain knowledge-base schema. Returns a list of problems (empty = valid). */
final class BrainKbValidator
{
    /** @return string[] */
    public static function check(mixed $d, ?string $expectId = null): array
    {
        if (!is_array($d)) return ['Not a JSON object'];
        $e = [];
        foreach (['id', 'name', 'bn', 'aliases', 'region', 'lat', 'lng', 'summary', 'best_months', 'season_note', 'tags', 'days', 'reach', 'local', 'attractions', 'food', 'stay', 'rules', 'packing'] as $k) if (!array_key_exists($k, $d)) $e[] = "missing field: $k";
        if ($e) return $e;
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', (string)$d['id'])) $e[] = 'id must be lowercase letters, digits and hyphens';
        if ($expectId !== null && $d['id'] !== $expectId) $e[] = 'id must match the file name';
        if (!is_numeric($d['lat']) || !is_numeric($d['lng']) || abs((float)$d['lat']) > 90 || abs((float)$d['lng']) > 180) $e[] = 'lat/lng out of range';
        if (!is_array($d['aliases']) || count($d['aliases']) < 2) $e[] = 'need at least 2 aliases';
        foreach ((array)$d['best_months'] as $m) if (!is_int($m) || $m < 1 || $m > 12) { $e[] = 'best_months must be integers 1-12'; break; }
        if (!is_array($d['attractions']) || count($d['attractions']) < 3) $e[] = 'need at least 3 attractions';
        $ids = [];
        foreach ((array)$d['attractions'] as $a) {
            $nm = is_array($a) ? (string)($a['name'] ?? '?') : '?';
            if (!is_array($a)) { $e[] = 'attraction is not an object'; continue; }
            foreach (['id', 'name', 'area', 'tags', 'mins', 'fee', 'what', 'tip', 'best', 'indoor'] as $k) if (!array_key_exists($k, $a)) $e[] = "attraction $nm missing $k";
            if (!in_array($a['best'] ?? '', ['morning', 'afternoon', 'sunset', 'any'], true)) $e[] = "attraction $nm: best must be morning, afternoon, sunset or any";
            if (!is_int($a['mins'] ?? null) || $a['mins'] < 20 || $a['mins'] > 600) $e[] = "attraction $nm: mins must be 20-600";
            if (isset($ids[$a['id'] ?? ''])) $e[] = 'duplicate attraction id ' . $a['id']; $ids[$a['id'] ?? ''] = 1;
        }
        if (!is_array($d['reach']) || !$d['reach']) $e[] = 'need at least 1 way to reach';
        foreach ((array)$d['reach'] as $r) foreach (['mode', 'how', 'hours', 'fare_min', 'fare_max'] as $k) if (!is_array($r) || !array_key_exists($k, $r)) { $e[] = "reach missing $k"; break; }
        if (!is_array($d['food']) || count($d['food']) < 1) $e[] = 'need at least 1 food item';
        foreach (['budget', 'mid', 'luxury'] as $t) {
            $s = $d['stay'][$t] ?? null;
            if (!is_array($s) || !isset($s['area'], $s['price_min'], $s['price_max'])) $e[] = "stay.$t incomplete"; elseif ($s['price_max'] < $s['price_min']) $e[] = "stay.$t: price_min is above price_max";
        }
        if (($d['country'] ?? 'Bangladesh') !== 'Bangladesh' && (!isset($d['emergency']['police'], $d['emergency']['ambulance']) || empty($d['entry_note']))) $e[] = 'non-Bangladesh places need emergency{police,ambulance} and entry_note';
        return array_values(array_unique($e));
    }

    /** A starting record for a new destination. */
    public static function template(string $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'bn' => $name, 'aliases' => [mb_strtolower($name, 'UTF-8'), str_replace('-', ' ', $id)], 'region' => '', 'lat' => 0, 'lng' => 0,
            'summary' => 'Describe the place in one or two sentences.', 'best_months' => [11, 12, 1, 2], 'season_note' => '', 'tags' => ['nature'], 'days' => ['min' => 1, 'ideal' => 2],
            'reach' => [['mode' => 'Bus', 'how' => 'From Dhaka', 'hours' => 4, 'fare_min' => 300, 'fare_max' => 800, 'note' => '']],
            'local' => [['mode' => 'CNG auto', 'group_fare' => '100-300 BDT', 'note' => 'Agree the fare first.']],
            'attractions' => array_map(fn($i) => ['id' => "spot$i", 'name' => "Place $i", 'area' => $name, 'tags' => ['nature'], 'mins' => 90, 'fee' => 0, 'what' => 'What visitors do here.', 'tip' => 'A practical tip.', 'best' => 'any', 'indoor' => false], [1, 2, 3]),
            'food' => [['dish' => 'Local dish', 'where' => 'Local restaurants', 'cost' => 250]],
            'stay' => ['budget' => ['area' => $name, 'price_min' => 1200, 'price_max' => 2500, 'note' => ''], 'mid' => ['area' => $name, 'price_min' => 3000, 'price_max' => 6000, 'note' => ''], 'luxury' => ['area' => $name, 'price_min' => 7000, 'price_max' => 14000, 'note' => '']],
            'rules' => ['Respect local customs.'], 'packing' => ['Water', 'Sun protection'], 'nearby' => []];
    }
}
