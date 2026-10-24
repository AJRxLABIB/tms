<?php
/**
 * OfflineAssistant — answers chat questions WITHOUT a language model, from the same read-only tools the AI assistant uses
 * (ToolRegistry: package / hotel / activity search, package details, comparison, cancellation info, destination guides,
 * the signed-in visitor's own bookings). Used by api/ai.php when no AI provider can answer, so "show me beach trips under
 * 30k" or "what is the cancellation policy" still gets a correct, grounded answer instead of an error.
 *
 *   - Routing is the existing deterministic ToolRouter (rules, follow-ups such as "the cheaper one" included).
 *   - Every figure is printed straight from the tool result; nothing is generated, estimated or guessed.
 *   - Demo/sample entries are labelled and never priced; availability is never claimed.
 *   - The visitor's own bookings come from the server session via the registry's ownership check, never from the message.
 *   - InjectionGuard still screens the text first.
 *
 * render() is pure (tool results in, text out) and unit-tested. The reply uses only constructs the chat widget allows:
 * **bold** and relative <a href="page.php?key=value"> anchors.
 */
final class OfflineAssistant
{
    public const MAX_STEPS = 3;

    private const T = [
        'en' => [
            'none'      => "I couldn't find anything in our catalogue for that. Try a place, a budget (for example \"under 30k\") or a number of days.",
            'hello'     => "Hi! I can search our packages, hotels and activities, compare packages, and explain cancellation rules. Try: \"beach packages under 30k\" or \"3 day trip to Sylhet\".",
            'offline'   => "Note: the conversational AI is offline right now, so I'm answering straight from our catalogue.",
            'signin'    => "Please sign in to see your own bookings.",
            'browse'    => "No exact match, so these are the best-rated options within your limits:",
            'packages'  => "Here is what our catalogue lists:",
            'hotels'    => "Hotels in our catalogue:",
            'activities'=> "Activities in our catalogue:",
            'demo'      => "sample entry, not a real listing",
            'availability' => "Check the booking page for dates and availability.",
            'cheapest'  => "Cheapest of these: ",
            'policy'    => "Cancellation policy",
            'no_policy' => "No cancellation policy is recorded for this item. Please contact support before booking.",
            'guide'     => "Destination guide",
            'best_time' => "Best time",
            'getting'   => "Getting there",
            'researched'=> "Researched on",
            'bookings'  => "Your recent bookings:",
            'no_bookings' => "You have no bookings yet.",
            'per_night' => "per night",
            'per_person'=> "per person",
        ],
        'bn' => [
            'none'      => "আমাদের ক্যাটালগে এ বিষয়ে কিছু পাইনি। কোনো জায়গা, বাজেট (যেমন \"৩০ হাজারের নিচে\") বা কত দিনের ট্রিপ — লিখে দেখুন।",
            'hello'     => "হ্যালো! আমি প্যাকেজ, হোটেল ও অ্যাক্টিভিটি খুঁজে দিতে, প্যাকেজ তুলনা করতে এবং বাতিলের নিয়ম বুঝিয়ে দিতে পারি। যেমন: \"৩০ হাজারের নিচে সমুদ্র সৈকতের প্যাকেজ\"।",
            'offline'   => "দ্রষ্টব্য: কথোপকথনের এআই এখন বন্ধ আছে, তাই আমি সরাসরি আমাদের ক্যাটালগ থেকে উত্তর দিচ্ছি।",
            'signin'    => "নিজের বুকিং দেখতে অনুগ্রহ করে সাইন ইন করুন।",
            'browse'    => "হুবহু মিল পাইনি, তাই আপনার সীমার মধ্যে সেরা রেটিংয়ের অপশনগুলো:",
            'packages'  => "আমাদের ক্যাটালগে যা আছে:",
            'hotels'    => "ক্যাটালগের হোটেল:",
            'activities'=> "ক্যাটালগের অ্যাক্টিভিটি:",
            'demo'      => "নমুনা এন্ট্রি, আসল লিস্টিং নয়",
            'availability' => "তারিখ ও সিট আছে কিনা বুকিং পেজে দেখে নিন।",
            'cheapest'  => "এগুলোর মধ্যে সবচেয়ে সস্তা: ",
            'policy'    => "বাতিলের নিয়ম",
            'no_policy' => "এর জন্য কোনো বাতিলের নিয়ম লেখা নেই। বুকিংয়ের আগে সাপোর্টের সাথে যোগাযোগ করুন।",
            'guide'     => "গন্তব্য গাইড",
            'best_time' => "ভ্রমণের সেরা সময়",
            'getting'   => "যাওয়ার উপায়",
            'researched'=> "তথ্য সংগ্রহের তারিখ",
            'bookings'  => "আপনার সাম্প্রতিক বুকিং:",
            'no_bookings' => "আপনার এখনো কোনো বুকিং নেই।",
            'per_night' => "প্রতি রাত",
            'per_person'=> "জনপ্রতি",
        ],
    ];

    private static function taka($n): string { return '৳ ' . number_format((int)$n); }

    /** Text that is safe inside the widget's anchor allow-list: no markup characters, no ampersands, bounded length. */
    private static function clean($s, int $max = 90): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
        $s = str_replace(['&', '<', '>', '"', "'"], ['and', '', '', '', '’'], $s);
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 1, 'UTF-8') . '…' : $s;
    }

    /** Anchor for a catalogue citation (relative page only), else just bold text. */
    private static function link(?array $cite, string $title): string
    {
        $title = self::clean($title, 80);
        $url = (string)($cite['url'] ?? '');
        if ($url !== '' && preg_match('/^[a-z0-9\-]{1,40}\.php\?[a-z_]{1,20}=[a-z0-9\-]{1,40}$/i', $url)) {
            return '<a href="' . $url . '">' . $title . '</a>';
        }
        return '**' . $title . '**';
    }

    /** @return string[] chip labels */
    private static function chips(array $tools, bool $bn): array
    {
        if (in_array('search_packages', $tools, true)) {
            return $bn ? ['তুলনা করো', 'কোনটা সস্তা?', 'প্রথম প্যাকেজের বাতিলের নিয়ম'] : ['Compare them', 'Which is the cheaper one?', 'Cancellation policy for the first one'];
        }
        return $bn ? ['সেরা রেটিংয়ের প্যাকেজ দেখাও', '২০ হাজারের নিচে প্যাকেজ'] : ['Show best-rated packages', 'Packages under 20k'];
    }

    /**
     * Pure rendering of executed tool results.
     * @param array<int,array{tool:string,ok:bool,data?:mixed,error?:string}> $results
     * @return array{reply:string,suggestions:string[],sources:array<int,array>,found:bool}
     */
    public static function render(array $results, string $lang, bool $browse = false): array
    {
        $bn = $lang === 'bn';
        $t = self::T[$bn ? 'bn' : 'en'];
        $parts = []; $cites = []; $tools = []; $found = false;

        $walk = function ($v) use (&$walk, &$cites) {
            if (is_array($v)) { if (isset($v['citation']['id'])) $cites[$v['citation']['id']] = $v['citation']; foreach ($v as $x) $walk($x); }
        };

        foreach ($results as $r) {
            $tool = (string)($r['tool'] ?? ''); $tools[] = $tool;
            if (empty($r['ok'])) {
                if (($r['error'] ?? '') === 'sign_in_required') $parts[] = $t['signin'];
                continue;
            }
            $d = $r['data'] ?? null;
            $walk($d);
            switch ($tool) {
                case 'search_packages':
                    if (!is_array($d) || !$d) break;
                    $found = true;
                    $lines = [($browse ? $t['browse'] : $t['packages'])];
                    foreach (array_slice($d, 0, 5) as $i => $p) {
                        $meta = array_filter([self::clean($p['location'] ?? '', 50), self::clean($p['duration'] ?? '', 30), isset($p['price_bdt']) ? self::taka($p['price_bdt']) : '']);
                        $lines[] = ($i + 1) . '. ' . self::link($p['citation'] ?? null, (string)($p['title'] ?? '')) . ($meta ? ' — ' . implode(' · ', $meta) : '');
                    }
                    $parts[] = implode("\n", $lines);
                    break;
                case 'get_package':
                    if (!is_array($d) || !empty($d['not_found'])) break;
                    $found = true;
                    $meta = array_filter([self::clean($d['location'] ?? '', 50), self::clean($d['duration'] ?? '', 30), isset($d['price']) ? self::taka($d['price']) : '']);
                    $parts[] = self::link($d['citation'] ?? null, (string)($d['title'] ?? '')) . ($meta ? ' — ' . implode(' · ', $meta) : '');
                    break;
                case 'get_cancellation_policy':
                    if (!is_array($d) || !empty($d['not_found'])) break;
                    $found = true;
                    $parts[] = '**' . $t['policy'] . '** (' . self::clean($d['package'] ?? '', 60) . '): ' . (!empty($d['policy']) ? self::clean($d['policy'], 500) : $t['no_policy']);
                    break;
                case 'compare_packages':
                    if (!is_array($d) || empty($d['packages'])) break;
                    $found = true;
                    $lines = [];
                    foreach ($d['packages'] as $p) {
                        $lines[] = '• ' . self::link($p['citation'] ?? null, (string)($p['title'] ?? '')) . ' — ' . self::clean($p['location'] ?? '', 40) . ' · ' . self::clean($p['duration'] ?? '', 30) . ' · ' . self::taka($p['price_bdt'] ?? 0);
                    }
                    if (!empty($d['cheapest_id'])) foreach ($d['packages'] as $p) if ((int)$p['id'] === (int)$d['cheapest_id']) { $lines[] = $t['cheapest'] . '**' . self::clean($p['title'] ?? '', 60) . '**'; }
                    $parts[] = implode("\n", $lines);
                    break;
                case 'search_hotels':
                case 'search_activities':
                    $rows = is_array($d) ? ($d['results'] ?? []) : [];
                    if (!$rows) break;
                    $found = true;
                    $lines = [$tool === 'search_hotels' ? $t['hotels'] : $t['activities']];
                    foreach (array_slice($rows, 0, 5) as $i => $h) {
                        $price = '';
                        if (empty($h['demo'])) {
                            if (isset($h['price_per_night_bdt'])) $price = self::taka($h['price_per_night_bdt']) . ' ' . $t['per_night'];
                            elseif (isset($h['price_per_person_bdt'])) $price = self::taka($h['price_per_person_bdt']) . ' ' . $t['per_person'];
                        }
                        $meta = array_filter([self::clean($h['city'] ?? '', 40), !empty($h['stars']) ? $h['stars'] . '★' : '', $price, !empty($h['demo']) ? '(' . $t['demo'] . ')' : '']);
                        $lines[] = ($i + 1) . '. ' . self::link($h['citation'] ?? null, (string)($h['title'] ?? '')) . ($meta ? ' — ' . implode(' · ', $meta) : '');
                    }
                    $lines[] = $t['availability'];
                    $parts[] = implode("\n", $lines);
                    break;
                case 'get_destination_guide':
                    $g = is_array($d) ? ($d['guides'] ?? []) : [];
                    if (!$g) break;
                    $found = true;
                    foreach (array_slice($g, 0, 1) as $x) {
                        $l = ['**' . $t['guide'] . ': ' . self::clean($x['place'] ?? '', 60) . '**'];
                        if (!empty($x['known_for'])) $l[] = self::clean(str_replace('|', ';', (string)$x['known_for']), 400);
                        if (!empty($x['best_time'])) $l[] = $t['best_time'] . ': ' . self::clean($x['best_time'], 240);
                        if (!empty($x['getting_there'])) $l[] = $t['getting'] . ': ' . self::clean($x['getting_there'], 300);
                        if (!empty($x['researched_on'])) $l[] = $t['researched'] . ' ' . self::clean($x['researched_on'], 20);
                        $parts[] = implode("\n", $l);
                    }
                    break;
                case 'get_cancellation_info':
                    if (!is_array($d)) break;
                    $l = [];
                    if (!empty($d['package_policy']['policy'])) $l[] = '**' . $t['policy'] . '** (' . self::clean($d['package_policy']['package'] ?? '', 60) . '): ' . self::clean($d['package_policy']['policy'], 500);
                    foreach ($d['site_policy'] ?? [] as $sp) $l[] = '**' . self::clean($sp['page'] ?? '', 20) . '**: ' . self::clean($sp['text'] ?? '', 500);
                    if (!$l) $l[] = $t['no_policy'];
                    $found = true;
                    $parts[] = implode("\n", $l);
                    break;
                case 'my_bookings':
                    $found = true;
                    if (!is_array($d) || !$d) { $parts[] = $t['no_bookings']; break; }
                    $lines = [$t['bookings']];
                    foreach ($d as $b) $lines[] = '• #' . (int)($b['booking_id'] ?? 0) . ' ' . self::clean($b['package'] ?? '', 60) . ' — ' . self::clean($b['from'] ?? '', 12) . ' → ' . self::clean($b['to'] ?? '', 12) . ' · ' . self::clean($b['status'] ?? '', 30) . ' · ' . self::clean($b['payment'] ?? '', 20);
                    $parts[] = implode("\n", $lines);
                    break;
                case 'get_my_booking':
                    if (!is_array($d) || !empty($d['not_found'])) { $parts[] = $bn ? 'এই নম্বরে আপনার কোনো বুকিং পাওয়া যায়নি।' : "I couldn't find that booking on your account."; break; }
                    $found = true;
                    $l = [];
                    foreach ($d as $k => $v) if (is_scalar($v) && $v !== '' && $k !== 'note') $l[] = '• ' . self::clean(str_replace('_', ' ', (string)$k), 30) . ': ' . self::clean((string)$v, 80);
                    $parts[] = implode("\n", $l);
                    break;
                default:
                    // Budget, readiness and notices: a plain list of the scalar facts the tool returned.
                    if (is_array($d)) {
                        $l = [];
                        foreach ($d as $k => $v) if (is_scalar($v) && $v !== '' && !in_array($k, ['note', 'rules'], true)) $l[] = '• ' . self::clean(str_replace('_', ' ', (string)$k), 40) . ': ' . self::clean((string)$v, 120);
                        if ($l) { $found = true; $parts[] = implode("\n", $l); }
                    }
            }
        }

        if (!$parts) { return ['reply' => $t['none'], 'suggestions' => self::chips($tools, $bn), 'sources' => [], 'found' => false]; }
        return ['reply' => implode("\n\n", $parts), 'suggestions' => self::chips($tools, $bn), 'sources' => array_values($cites), 'found' => $found];
    }

    /** Reply text for surfaces that render plain text only: anchors become their label, **bold** markers are dropped. */
    public static function plain(string $reply): string
    {
        $reply = preg_replace('/<a\s[^>]*>(.*?)<\/a>/is', '$1', $reply);
        $reply = str_replace('**', '', (string)$reply);
        return trim(strip_tags($reply));
    }

    /**
     * Full no-model turn. Same return shape the chat endpoint expects from SiteAssistant::reply().
     * @return array{success:bool,reply:string,suggestions:string[],sources:array,actions:array,error:string,offline:bool}
     */
    public static function reply(PDO $db, string $message, array $history, string $email = 'guest', string $pageContext = '', string $language = 'en', bool $addNote = false): array
    {
        require_once __DIR__ . '/InjectionGuard.php';
        require_once __DIR__ . '/ToolRouter.php';
        require_once __DIR__ . '/ConversationState.php';
        require_once __DIR__ . '/BanglaNormalizer.php';
        $lang = (BanglaNormalizer::language($message) === 'bn' || $language === 'bn') ? 'bn' : 'en';
        $t = self::T[$lang];
        $out = ['success' => true, 'reply' => '', 'suggestions' => [], 'sources' => [], 'actions' => [], 'error' => '', 'offline' => true];

        $guard = InjectionGuard::assess($message);
        if ($guard['block']) { $out['reply'] = InjectionGuard::refusal($language); return $out; }
        if ($pageContext !== '' && InjectionGuard::assess($pageContext)['block']) $pageContext = '';

        $signedIn = ($email !== 'guest' && $email !== '');
        $results = []; $browse = false;
        try {
            $pkgCtx = [];
            if (preg_match('/pkgid=(\d+)/', $pageContext, $pm)) $pkgCtx['package_id'] = (int)$pm[1];
            $registry = new ToolRegistry(KnowledgeIndex::fromDb($db), $db, null);   // no embedding runtime needed: BM25 only
            $recent = FollowUp::recentIds($history); $prices = [];
            foreach ($recent as $rid) { $rp = $registry->package($rid); if ($rp) $prices[$rid] = (int)$rp['price']; }
            $planned = ToolRouter::planWithState($message, $pkgCtx, $recent, $prices, ConversationState::load());
            ConversationState::save($planned['state']['search'], $planned['state']['focus']);
            foreach (array_slice($planned['calls'], 0, self::MAX_STEPS) as $c) {
                $r = $registry->execute($c['tool'], $c['args'], ['email' => $email, 'signed_in' => $signedIn]);
                $results[] = ['tool' => $c['tool']] + $r;
                if ($c['tool'] === 'search_packages') $browse = !empty($registry->lastSearchMeta['browse']);
            }
        } catch (Throwable $e) {
            error_log('[TourBuddy OfflineAssistant] ' . $e->getMessage());
        }

        if (!$results) { $out['reply'] = $t['hello']; $out['suggestions'] = self::chips([], $lang === 'bn'); }
        else { $r = self::render($results, $lang, $browse); $out['reply'] = $r['reply']; $out['suggestions'] = $r['suggestions']; $out['sources'] = $r['sources']; }
        if ($addNote) $out['reply'] .= "\n\n" . $t['offline'];
        return $out;
    }
}
