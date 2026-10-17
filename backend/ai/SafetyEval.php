<?php
/**
 * Deterministic safety/retrieval evaluation of the AI layer (no model, no network,
 * no real catalogue). Shared by tests/ai/run_eval.php (CLI) and admin/ai-safety.php.
 * Fixture packages are TEST DATA ONLY. This does NOT measure answer quality.
 */
declare(strict_types=1);
require_once __DIR__ . '/ToolRouter.php';
require_once __DIR__ . '/InjectionGuard.php';

final class SafetyEval
{
    /** @return array{pass:int,fail:int,rows:array<int,array{0:bool,1:string,2:string}>} */
    public static function run(string $casesFile): array
    {
        $fixture = [
         ['PackageId'=>1,'PackageName'=>"Cox's Bazar Beach Retreat",'PackageType'=>'Beach','PackageLocation'=>"Cox's Bazar",'Country'=>'Bangladesh','PackagePrice'=>15000,'PackageDuration'=>'3 Days','PackageFetures'=>'sea beach sunset hotel','CancellationPolicy'=>'Full refund 14+ days before departure.'],
         ['PackageId'=>2,'PackageName'=>'Sundarbans Wildlife Safari','PackageType'=>'Wildlife','PackageLocation'=>'Khulna','Country'=>'Bangladesh','PackagePrice'=>22000,'PackageDuration'=>'4 Days','PackageFetures'=>'mangrove forest tiger boat sundarbans'],
         ['PackageId'=>3,'PackageName'=>'Bandarban Hill Escape','PackageType'=>'Hills','PackageLocation'=>'Bandarban','Country'=>'Bangladesh','PackagePrice'=>18000,'PackageDuration'=>'3 Days','PackageFetures'=>'hill cloud trekking tribal'],
         ['PackageId'=>4,'PackageName'=>'Bhutan Culture Tour','PackageType'=>'International','PackageLocation'=>'Thimphu','Country'=>'Bhutan','PackagePrice'=>85000,'PackageDuration'=>'6 Days','PackageFetures'=>'monastery culture'],
         ['PackageId'=>5,'PackageName'=>'Sylhet Tea Garden Weekend','PackageType'=>'Nature','PackageLocation'=>'Sylhet','Country'=>'Bangladesh','PackagePrice'=>12000,'PackageDuration'=>'2 Days','PackageFetures'=>'tea garden waterfall'],
        ];
        $cases = json_decode(file_get_contents($casesFile), true);
        $ix = KnowledgeIndex::fromRows($fixture);
        $reg = new ToolRegistry($ix, null);
        $pass = 0; $fail = 0; $rows = [];
        $rec = function (string $id, bool $ok, string $detail = '') use (&$pass, &$fail, &$rows) {
            $ok ? $pass++ : $fail++; $rows[] = [$ok, $id, $detail];
        };

        foreach ($cases['guard'] as $c) {
            $a = InjectionGuard::assess($c['text']);
            $rec($c['id'], $a['block'] === $c['block'], 'risk=' . $a['risk'] . ' flags=' . implode(',', $a['flags']));
        }
        foreach ($cases['retrieval'] as $c) {
            $h = $ix->search($c['query'], 3);
            if (!empty($c['expect_none'])) $rec($c['id'], count($h) === 0, 'hits=' . count($h));
            else $rec($c['id'], ($h[0]['id'] ?? 0) === $c['expect_top'], 'top=' . ($h[0]['id'] ?? 'none'));
        }
        foreach ($cases['routing'] as $c) {
            $ctx = isset($c['ctx_package']) ? ['package_id' => $c['ctx_package']] : [];
            $plan = ToolRouter::plan($c['message'], $ctx);
            $tools = array_column($plan, 'tool');
            $ok = !array_diff($c['expect_tools'], $tools);
            if ($ok && isset($c['expect_max_price'])) {
                $ok = false;
                foreach ($plan as $p) if (($p['args']['max_price'] ?? null) === $c['expect_max_price']) $ok = true;
            }
            $rec($c['id'], $ok, 'tools=' . implode(',', $tools));
        }
        foreach ($cases['tools'] as $c) {
            $r = $reg->execute($c['tool'], $c['args'], ['signed_in' => $c['signed_in'] ?? false, 'email' => 'tester@example.test']);
            $ok = isset($c['expect_ok']) ? $r['ok'] === $c['expect_ok'] : (($r['error'] ?? '') === $c['expect_error']);
            $rec($c['id'], $ok, json_encode($r['ok'] ? 'ok' : $r['error']));
        }
        foreach ($cases['sanitize'] as $c) {
            $o = InjectionGuard::sanitizeReply($c['in']); $ok = true;
            foreach ($c['must_not_contain'] ?? [] as $x) if (stripos($o, $x) !== false) $ok = false;
            foreach ($c['must_contain'] ?? [] as $x) if (strpos($o, $x) === false) $ok = false;
            $rec($c['id'], $ok, $o);
        }
        // Extra structural checks (a new tool name must be allowlisted here on purpose; check_booking_readiness and compare_packages are read-only, see booking_readiness_test.php and followup_test.php)
        $rec('x1_no_write_tools', !array_filter($reg->names(), fn($n) => !in_array($n, ['check_booking_readiness', 'compare_packages'], true) && !preg_match('/^(get_|search_|my_|calculate_)/', $n)), implode(',', $reg->names()));
        $rec('x2_cancel_policy_cited', ($reg->execute('get_cancellation_policy', ['package_id' => 1], [])['data']['citation']['url'] ?? '') === 'package-details.php?pkgid=1');
        $rec('x3_missing_policy_not_invented', (function () use ($reg) { $d = $reg->execute('get_cancellation_policy', ['package_id' => 2], [])['data']; return array_key_exists('policy', $d) && $d['policy'] === null; })());
        $rec('x4_price_filter', !array_filter($ix->search('package', 10, ['max_price' => 15000]), fn($h) => $h['meta']['price'] > 15000));


        return ['pass' => $pass, 'fail' => $fail, 'rows' => $rows];
    }
}
