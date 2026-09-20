<?php
/**
 * PlaceDetailsService.php — Explore Mode / Nearby Places detail panel
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * REWRITTEN (Round 14) — this service used to fabricate almost everything.
 *
 * Nearby Places lists real, live places from OpenStreetMap (and Google Places
 * when a key is configured). Those sources expose a handful of fields: name,
 * coordinates, address, phone, opening hours, website, and sometimes a rating.
 *
 * The previous version took that real place and invented the rest from a hash
 * of its name — a phone number, an email address, a website URL, Facebook and
 * Instagram profiles, a star rating, a review count, three named customer
 * reviews, five stock photos, room rates, a full restaurant menu with prices,
 * hospital departments, named doctors, and a named police officer-in-charge —
 * and rendered all of it as fact, with no "estimated" marker anywhere.
 *
 * That was wrong in ordinary cases (a "Website" link pointing at a domain the
 * business does not own; invented reviews attributed to invented people) and
 * unsafe in the ones that matter most: a real, named hospital was asserted to
 * have 24/7 ambulance dispatch and four specific doctors, and a police station
 * was given an emergency number that is wrong for this platform's main market.
 *
 * This version shows only what the upstream source actually provided, says
 * plainly when a field is unknown, and separates genuinely useful general
 * guidance (clearly labelled as general) from claims about the specific place.
 * `sections` keeps the same shape, so the existing renderer still works.
 * ══════════════════════════════════════════════════════════════════════════════
 */

final class PlaceDetailsService
{
    /**
     * Build the detail record for one real place.
     *
     * @param array  $place    Real upstream fields: name, lat, lng, address,
     *                         phone, opening_hours, website, rating, distance_m.
     *                         Anything absent stays absent — nothing is invented.
     * @param string $category One of the CATEGORY_MAP keys in ExploreService.
     */
    public function build(array $place, string $category): array
    {
        $name = trim((string)($place['name'] ?? ''));
        if ($name === '') {
            $name = 'Unnamed place';
        }
        $lat = (float)($place['lat'] ?? 0);
        $lng = (float)($place['lng'] ?? 0);

        $openingHours = $this->cleanString($place['opening_hours'] ?? null);
        $rating       = isset($place['rating']) && $place['rating'] !== null && $place['rating'] !== ''
            ? round((float)$place['rating'], 1)
            : null;

        $distanceKm = isset($place['distance_m']) && $place['distance_m'] !== null
            ? round(((float)$place['distance_m']) / 1000, 1)
            : null;

        $common = [
            'name'           => $name,
            'category'       => $category,
            'category_label' => $this->categoryLabel($category),

            // Real-or-null. The renderer prints an explicit "not listed" line
            // for each of these rather than a fabricated value.
            'address'        => $this->cleanString($place['address'] ?? null),
            'phone'          => $this->cleanString($place['phone'] ?? null),
            'website'        => $this->safeUrl($place['website'] ?? null),
            'opening_hours'  => $openingHours,

            // Deliberately removed: email and social profiles were invented from
            // a slug of the place's name for 100% of places (OSM never supplies
            // them here), which meant every "Website"/"Facebook"/"Instagram"
            // link led somewhere the business does not control.
            'email'          => null,
            'social'         => null,

            'map_url'        => "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}",
            // LIVE BUG FIX (Round 114, found via live browser testing): this used
            // to point at maps.google.com (no "www") -- Google accepts that host
            // for a top-level page, but this project's own CSP `frame-src` only
            // allows `https://www.google.com` (see .htaccess), and this URL is
            // rendered inside an <iframe> by js/explore.js's renderPlaceDetail().
            // The browser silently blocked the frame on every single place detail
            // view in Explore Mode -- the embedded mini-map never rendered, with
            // only a console CSP violation to show for it, nothing visible to a
            // user who wasn't watching devtools. www.google.com/maps?...&output=
            // embed is the same embed endpoint, just the CSP-compliant host.
            'map_embed_url'  => "https://www.google.com/maps?q={$lat},{$lng}&z=15&output=embed",
            'osm_url'        => "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map=17/{$lat}/{$lng}",
            'lat'            => $lat,
            'lng'            => $lng,
            'distance_km'    => $distanceKm,

            // Only ever a real rating (Google Places). OpenStreetMap has none,
            // so on the default configuration these stay null and the badge is
            // simply not rendered — instead of a hash-derived "4.3 (512)".
            'rating'         => $rating,
            'review_count'   => null,

            // null (not false) means "we can't tell" — the badge is hidden
            // rather than asserting Open/Closed. See openNowFromOsm().
            //
            // FIX (Round 114, package/places/map upgrade): $place can now
            // carry a real, pre-computed is_open_now (see ExploreService::
            // parseOverpassPlaces()/parseGooglePlaces(), which attach one to
            // every place in the Nearby Places LIST, and api/explore.php's
            // handlePlaceDetails(), which passes the card's value through).
            // For a Google-sourced place, $openingHours here is already the
            // display string "Open now"/"Closed now" -- openNowFromOsm() is
            // written to parse OSM's hours syntax, not that string, and
            // re-parsing it never actually resolved to true/false (it fell
            // through to null every time, as an "unrecognised day token").
            // Prefer the real, already-known value when the caller has one;
            // fall back to parsing $openingHours only when it doesn't (the
            // original behaviour, still exactly right for OSM-sourced places
            // reached some other way).
            'is_open_now'    => array_key_exists('is_open_now', $place) && $place['is_open_now'] !== null
                ? (bool)$place['is_open_now']
                : self::openNowFromOsm($openingHours),

            // No stock photography. picsum.photos images seeded on the place's
            // name were being presented as photos OF that place.
            'hero_image'     => null,
            'images'         => [],

            'source_label'   => 'OpenStreetMap contributors',
        ];

        $common['sections'] = $this->sectionsFor($category, $common);

        return $common;
    }

    // ════════════════════════════════════════════════════════════════════════
    // SECTIONS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Sections are either (a) facts we actually have, or (b) general guidance
     * that is true of the category, never of this specific place. Every
     * guidance section says so in its own title, so a reader can never mistake
     * it for verified information about the place they are looking at.
     */
    private function sectionsFor(string $category, array $common): array
    {
        $sections = [];

        // ── (a) What we know ──
        $known = [];
        if ($common['address'] !== null) {
            $known[] = ['icon' => 'fa-map-marker', 'label' => 'Address', 'value' => $common['address']];
        }
        if ($common['phone'] !== null) {
            $known[] = ['icon' => 'fa-phone', 'label' => 'Phone', 'value' => $common['phone']];
        }
        if ($common['opening_hours'] !== null) {
            $known[] = ['icon' => 'fa-clock', 'label' => 'Opening hours', 'value' => $common['opening_hours']];
        }
        if ($common['distance_km'] !== null) {
            $known[] = [
                'icon'  => 'fa-route',
                'label' => 'Distance',
                'value' => $common['distance_km'] . ' km from the destination centre (straight line)',
            ];
        }
        if ($known) {
            $sections[] = [
                'type'  => 'info_grid',
                'title' => 'Verified details',
                'icon'  => 'fa-check-circle',
                'items' => $known,
            ];
        }

        // ── (b) Emergency categories get real guidance, never invented specifics ──
        if ($category === 'hospital' || $category === 'police') {
            $sections[] = [
                'type'  => 'info_grid',
                'title' => 'In an emergency',
                'icon'  => 'fa-exclamation-triangle',
                'items' => [
                    [
                        'icon'  => 'fa-phone',
                        'label' => 'Call the local emergency number',
                        'value' => 'Dial your destination\'s emergency line — 999 in Bangladesh and the UK, '
                                 . '911 in the US and Canada, 112 across the EU and on any GSM phone, '
                                 . '112 or 100/108 in India. Do not rely on a number listed on this page.',
                    ],
                    [
                        'icon'  => 'fa-id-card',
                        'label' => 'Travel insurance',
                        'value' => 'Your insurer\'s 24-hour assistance line is on your policy card and can '
                                 . 'direct you to a facility that accepts your cover.',
                    ],
                    [
                        'icon'  => 'fa-info-circle',
                        'label' => 'Before relying on this location',
                        'value' => 'Opening hours and the services actually offered here are not verified. '
                                 . 'Call ahead, or use the Google Maps link for up-to-date contact details.',
                    ],
                ],
            ];
        }

        // ── (c) Category guidance — general, and labelled as such ──
        $tips = $this->generalTips($category);
        if ($tips) {
            $sections[] = [
                'type'  => 'list',
                'title' => 'General tips for ' . $this->categoryTipLabel($category) . ' (not specific to this place)',
                'icon'  => 'fa-lightbulb-o',
                'items' => $tips,
            ];
        }

        // ── (d) Provenance ──
        $sections[] = [
            'type'    => 'text',
            'title'   => 'Where this information comes from',
            'icon'    => 'fa-database',
            'content' => 'The name, location and any details above come from open map data ('
                       . $common['source_label'] . '). We show only what that data actually contains, '
                       . 'so some fields may be missing — we would rather leave a gap than guess. For '
                       . 'prices, menus, room availability, photos or reviews, use the links above to '
                       . 'the place\'s own listing.',
        ];

        return $sections;
    }

    /**
     * Honest, genuinely useful advice that applies to the category as a whole.
     * Nothing here is a claim about any particular business.
     */
    private function generalTips(string $category): array
    {
        switch ($category) {
            case 'hotel':
                return [
                    'Confirm the room rate and what it includes (breakfast, taxes, service charge) in writing before you arrive.',
                    'Ask about check-in and check-out times — they vary a lot, and early check-in is rarely free.',
                    'If you are arriving late at night, tell the property in advance so the booking is held.',
                    'Photograph the room on arrival if you are paying a security deposit.',
                ];
            case 'restaurant':
                return [
                    'Ask whether service charge and VAT are already included in the listed prices.',
                    'If you have an allergy or dietary requirement, confirm it at the table rather than assuming from the menu.',
                    'Busy places often stop seating well before their stated closing time.',
                    'Card acceptance varies — carry some cash as a fallback.',
                ];
            case 'attraction':
                return [
                    'Check the last-entry time, which is usually earlier than the closing time.',
                    'Book online in advance where possible — it is often cheaper and skips the queue.',
                    'Student, child, senior and family tickets are commonly available but rarely advertised.',
                    'Some sites have a dress code or restrict photography, particularly religious ones.',
                ];
            case 'tourist_info':
                return [
                    'Official tourist offices usually have free printed maps and current transport timetables.',
                    'They can confirm whether an attraction is genuinely open today — more reliable than online listings.',
                    'Ask about city passes if you plan to visit several paid sites.',
                ];
            case 'shopping_mall':
                return [
                    'Weekend and holiday hours frequently differ from weekday hours.',
                    'Keep receipts if you may want to claim a tax refund on the way home.',
                    'Agree a price before buying at stalls where items are not individually priced.',
                ];
            case 'gas_station':
                return [
                    'Fuel type names differ by country — check the pump label against your vehicle before filling.',
                    'Not every station accepts foreign cards; some are unattended and card-only.',
                    'Rural stations may close overnight even where city ones run 24 hours.',
                ];
            case 'pharmacy':
                return [
                    'Bring the generic (chemical) name of any medication — brand names change between countries.',
                    'Some medicines sold over the counter at home need a prescription here, and the reverse.',
                    'Ask which nearby pharmacy holds the overnight rota if you need something out of hours.',
                ];
            case 'atm':
                return [
                    'Decline the machine\'s own currency conversion — your bank\'s rate is almost always better.',
                    'ATMs attached to a bank branch are safer and easier to resolve problems with.',
                    'Check your card\'s foreign withdrawal fee; fewer, larger withdrawals usually cost less.',
                ];
            case 'hospital':
            case 'police':
                return []; // covered by the dedicated emergency section above
            default:
                return [];
        }
    }

    private function categoryTipLabel(string $category): string
    {
        return [
            'hotel'         => 'hotels and guesthouses',
            'restaurant'    => 'eating out',
            'attraction'    => 'visiting attractions',
            'tourist_info'  => 'tourist information offices',
            'shopping_mall' => 'shopping',
            'gas_station'   => 'refuelling abroad',
            'pharmacy'      => 'pharmacies abroad',
            'atm'           => 'using ATMs abroad',
        ][$category] ?? 'this kind of place';
    }

    private function categoryLabel(string $category): string
    {
        return [
            'hotel'         => 'Hotel',
            'restaurant'    => 'Restaurant',
            'attraction'    => 'Tourist Attraction',
            'tourist_info'  => 'Tourist Information Centre',
            'shopping_mall' => 'Shopping',
            'gas_station'   => 'Fuel Station',
            'hospital'      => 'Hospital',
            'police'        => 'Police Station',
            'pharmacy'      => 'Pharmacy',
            'atm'           => 'Bank / ATM',
        ][$category] ?? 'Place';
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════════════

    private function cleanString($value): ?string
    {
        if ($value === null) return null;
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    /** Only return a URL we are willing to put behind a link. */
    private function safeUrl($value): ?string
    {
        $v = $this->cleanString($value);
        if ($v === null) return null;
        if (!preg_match('#^https?://#i', $v)) {
            $v = 'https://' . ltrim($v, '/');
        }
        return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
    }

    /**
     * Decide "open now" from a real OpenStreetMap opening_hours value.
     *
     * Returns true/false only when the value is understood, and null when it is
     * absent or in a syntax we don't parse — in which case the renderer hides
     * the badge and shows the raw hours string instead. The previous
     * implementation returned a seeded coin-flip (72% "open"), constant for the
     * lifetime of the place, so a restaurant showed a definitive green "Open
     * Now" at 4am forever, and it ignored the real hours it had been handed.
     *
     * Handles the common forms: "24/7", "Mo-Fr 09:00-17:00", "Mo-Su 08:00-22:00",
     * "Mo-Sa 10:00-20:00; Su off", and comma/semicolon-separated combinations.
     * Deliberately does NOT try to handle public holidays, seasonal ranges or
     * "sunset" style values — those return null.
     *
     * FIX (Round 114, package/places/map upgrade): widened from private to
     * public static so ExploreService can reuse this exact same parser to
     * attach is_open_now to every place in a Nearby Places LIST (not just
     * the single-place detail modal this class was originally written for),
     * instead of a second, separately-maintained copy of this parsing logic
     * living in ExploreService. It never referenced $this, so making it
     * static changes no behaviour for the one call site above (self:: now,
     * was $this->).
     */
    public static function openNowFromOsm(?string $hours): ?bool
    {
        if ($hours === null) return null;
        $h = strtolower(trim($hours));
        if ($h === '') return null;

        if (str_contains($h, '24/7') || str_contains($h, 'open 24 hours')) return true;

        // Anything with these constructs is beyond this simple parser.
        foreach (['ph ', 'ph,', 'sunrise', 'sunset', 'week', 'jan', 'feb', 'mar', 'apr',
                  'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $tooComplex) {
            if (str_contains($h, $tooComplex)) return null;
        }

        $dayIndex = ['mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6, 'su' => 7];
        $today    = (int)date('N');       // 1 = Monday
        $nowMin   = (int)date('G') * 60 + (int)date('i');

        $matchedToday = false;

        foreach (preg_split('/\s*;\s*/', $h) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;

            if (!preg_match('/^([a-z,\-\s]*?)\s*([\d:,\-\s]*(?:off|closed)?)$/', $rule, $m)) continue;
            $dayPart  = trim($m[1]);
            $timePart = trim($m[2]);

            // Which days does this rule cover? An empty day part means daily.
            $days = [];
            if ($dayPart === '') {
                $days = range(1, 7);
            } else {
                foreach (preg_split('/\s*,\s*/', $dayPart) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk === '') continue;
                    if (preg_match('/^([a-z]{2})\s*-\s*([a-z]{2})$/', $chunk, $dm)) {
                        $from = $dayIndex[$dm[1]] ?? null;
                        $to   = $dayIndex[$dm[2]] ?? null;
                        if ($from === null || $to === null) return null;
                        $guard = 0;
                        for ($d = $from; ; $d = ($d % 7) + 1) {
                            $days[] = $d;
                            if ($d === $to) break;
                            if (++$guard > 7) return null;
                        }
                    } elseif (isset($dayIndex[$chunk])) {
                        $days[] = $dayIndex[$chunk];
                    } else {
                        return null; // unrecognised day token
                    }
                }
            }

            if (!in_array($today, $days, true)) continue;
            $matchedToday = true;

            if ($timePart === 'off' || $timePart === 'closed') return false;

            $sawTimeRange = false;
            foreach (preg_split('/\s*,\s*/', $timePart) as $span) {
                if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($span), $tm)) {
                    continue;
                }
                $sawTimeRange = true;
                $start = (int)$tm[1] * 60 + (int)$tm[2];
                $end   = (int)$tm[3] * 60 + (int)$tm[4];
                if ($end <= $start) {
                    // Crosses midnight, e.g. 20:00-02:00
                    if ($nowMin >= $start || $nowMin < $end) return true;
                } elseif ($nowMin >= $start && $nowMin < $end) {
                    return true;
                }
            }
            if (!$sawTimeRange) return null; // today's rule was in a form we don't parse
        }

        // A rule covered today and none of its spans contained "now" → closed.
        // No rule covered today at all → we can't say.
        return $matchedToday ? false : null;
    }
}
