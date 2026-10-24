<?php
/**
 * RouteOptimizer — deterministic visiting order for a day's stops (nearest neighbour, then 2-opt), on straight-line
 * (haversine) distances. No AI, no network, same input => same output.
 *
 * Honest limits: straight-line distance is not road distance, so "km saved" is an estimate of how much backtracking was
 * removed, not a driving-time promise. Stops without coordinates are never moved: they are reported as 'unplaced' and the
 * caller decides what to do with them.
 */
final class RouteOptimizer
{
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** @param array<int,array{lat:float,lng:float}> $pts open path (not a loop) in the given order */
    public static function pathLength(array $pts, bool $loop = false): float
    {
        $n = count($pts); $km = 0.0;
        for ($i = 0; $i + 1 < $n; $i++) $km += self::haversineKm($pts[$i]['lat'], $pts[$i]['lng'], $pts[$i + 1]['lat'], $pts[$i + 1]['lng']);
        if ($loop && $n > 2) $km += self::haversineKm($pts[$n - 1]['lat'], $pts[$n - 1]['lng'], $pts[0]['lat'], $pts[0]['lng']);
        return $km;
    }

    /**
     * @param array<int,array{id:mixed,lat:?float,lng:?float}> $stops in the traveller's current order
     * @param bool $fixStart keep the first stop first (e.g. the hotel) — default true
     * @param bool $loop treat the route as returning to the start (e.g. back to the hotel)
     * @return array{order:array,distance_km:float,original_km:float,saved_km:float,unplaced:array,changed:bool}
     */
    public static function optimize(array $stops, bool $fixStart = true, bool $loop = false): array
    {
        $placed = []; $unplaced = [];
        foreach (array_values($stops) as $i => $s) {
            if (isset($s['lat'], $s['lng']) && is_numeric($s['lat']) && is_numeric($s['lng'])) $placed[] = ['id' => $s['id'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'pos' => $i];
            else $unplaced[] = $s['id'];
        }
        $orig = array_column($placed, 'id');
        $origKm = self::pathLength($placed, $loop);
        $n = count($placed);
        if ($n < 3) return ['order' => $orig, 'distance_km' => $origKm, 'original_km' => $origKm, 'saved_km' => 0.0, 'unplaced' => $unplaced, 'changed' => false];

        // nearest neighbour from the start (ties broken by original position => deterministic)
        $left = $placed; $route = [];
        $route[] = array_shift($left);                    // start: first stop in current order
        if (!$fixStart) {                                 // otherwise pick the best start by trying each as start
            $best = null;
            foreach ($placed as $startIdx => $_) {
                $l = $placed; $r = [array_splice($l, $startIdx, 1)[0]];
                $r = self::nearest($r, $l);
                $len = self::pathLength($r, $loop);
                if ($best === null || $len < $best[0] - 1e-9) $best = [$len, $r];
            }
            $route = $best[1];
        } else {
            $route = self::nearest($route, $left);
        }
        $route = self::twoOpt($route, $fixStart, $loop);
        $newKm = self::pathLength($route, $loop);

        // never return something worse than what the traveller already has
        if ($newKm >= $origKm - 1e-9) return ['order' => $orig, 'distance_km' => $origKm, 'original_km' => $origKm, 'saved_km' => 0.0, 'unplaced' => $unplaced, 'changed' => false];
        $order = array_column($route, 'id');
        return ['order' => $order, 'distance_km' => $newKm, 'original_km' => $origKm, 'saved_km' => $origKm - $newKm, 'unplaced' => $unplaced, 'changed' => $order !== $orig];
    }

    private static function nearest(array $route, array $left): array
    {
        while ($left) {
            $cur = end($route); $bi = 0; $bd = INF;
            foreach ($left as $i => $c) {
                $d = self::haversineKm($cur['lat'], $cur['lng'], $c['lat'], $c['lng']);
                if ($d < $bd - 1e-12 || (abs($d - $bd) <= 1e-12 && $c['pos'] < $left[$bi]['pos'])) { $bd = $d; $bi = $i; }
            }
            $route[] = $left[$bi]; array_splice($left, $bi, 1);
        }
        return $route;
    }

    private static function twoOpt(array $route, bool $fixStart, bool $loop): array
    {
        $n = count($route); $from = $fixStart ? 1 : 0; $improved = true; $guard = 0;
        while ($improved && $guard++ < 100) {
            $improved = false;
            for ($i = $from; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $cand = $route;
                    array_splice($cand, $i, $j - $i + 1, array_reverse(array_slice($route, $i, $j - $i + 1)));
                    if (self::pathLength($cand, $loop) < self::pathLength($route, $loop) - 1e-9) { $route = $cand; $improved = true; }
                }
            }
        }
        return $route;
    }
}
