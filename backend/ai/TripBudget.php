<?php
/**
 * TripBudget — deterministic trip cost arithmetic. Whole taka only (the schema stores money as INT BDT); no floats.
 *
 * It only ADDS UP numbers it is given. Every line says where its number came from:
 *   catalogue        the package's list/quoted price per person (from the database, never from the model)
 *   user_estimate    a figure the traveller supplied (food per day, local transport ...)
 *   assumption       a rule applied by this calculator (contingency %)
 * Costs it was not given (hotel extras, flights, visas, shopping ...) are listed under `not_included`, and the verdict is
 * always "within / over the KNOWN costs", never "within budget" as if everything were counted.
 */
final class TripBudget
{
    public const MAX_TAKA = 1000000000;

    /**
     * @param array{travelers:int,package_price_per_person?:int,package_price_basis?:string,days?:int,budget?:int,
     *              food_per_person_per_day?:int,local_transport?:int,activities?:int,other?:int,contingency_pct?:int} $in
     * @return array<string,mixed>
     */
    public static function compute(array $in): array
    {
        $trav = max(1, min(50, (int)($in['travelers'] ?? 1)));
        $days = isset($in['days']) ? max(1, min(60, (int)$in['days'])) : null;
        $pct  = max(0, min(30, (int)($in['contingency_pct'] ?? 10)));
        $lines = []; $notIncluded = []; $sub = 0;
        $add = function (string $key, string $label, int $amount, string $source, string $basis) use (&$lines, &$sub) {
            $amount = max(0, min(self::MAX_TAKA, $amount));
            $lines[] = ['key' => $key, 'label' => $label, 'amount_bdt' => $amount, 'source' => $source, 'basis' => $basis];
            $sub += $amount;
        };

        if (isset($in['package_price_per_person']) && (int)$in['package_price_per_person'] > 0) {
            $pp = (int)$in['package_price_per_person'];
            $add('package', 'Package price', $pp * $trav, 'catalogue', "{$pp} x {$trav} traveller(s), " . ($in['package_price_basis'] ?? 'list price') . ' per person');
        } else {
            $notIncluded[] = 'package price (no package selected)';
        }
        if (!empty($in['food_per_person_per_day'])) {
            if ($days === null) { $notIncluded[] = 'food (number of days not known)'; }
            else { $f = (int)$in['food_per_person_per_day']; $add('food', 'Food allowance', $f * $trav * $days, 'user_estimate', "{$f} x {$trav} x {$days} day(s)"); }
        } else { $notIncluded[] = 'food'; }
        foreach (['local_transport' => 'Local transport', 'activities' => 'Activities and tickets', 'other' => 'Other costs'] as $k => $label) {
            if (!empty($in[$k])) $add($k, $label, (int)$in[$k], 'user_estimate', 'total supplied by the traveller');
            elseif ($k !== 'other') $notIncluded[] = strtolower($label);
        }

        $contingency = intdiv($sub * $pct + 50, 100); // round half up, integer math
        if ($pct > 0 && $sub > 0) $add('contingency', "Contingency ({$pct}%)", $contingency, 'assumption', "{$pct}% of known costs, rounded to whole taka");
        $total = $sub;
        $perPerson = intdiv($total + intdiv($trav, 2), $trav);

        $out = ['currency' => 'BDT', 'travelers' => $trav, 'days' => $days, 'lines' => $lines, 'total_bdt' => $total, 'per_person_bdt' => $perPerson,
                'not_included' => $notIncluded];
        if (isset($in['budget']) && (int)$in['budget'] > 0) {
            $b = (int)$in['budget'];
            $out['budget_bdt'] = $b;
            $out['difference_bdt'] = $b - $total;           // positive = headroom, negative = over
            $out['verdict'] = $total <= $b ? 'within_budget_for_known_costs' : 'over_budget';
            if ($total > $b) $out['over_by_bdt'] = $total - $b;
        } else {
            $out['verdict'] = 'no_budget_given';
        }
        $out['note'] = 'Totals add only the listed costs. Items under not_included are NOT counted, so treat the result as a minimum. '
                     . 'The package figure is a catalogue price; the final amount is confirmed at checkout.';
        return $out;
    }
}
