<?php
/**
 * PlaceDetailsService.php — Explore Mode / Nearby Places detail pages
 *
 * Nearby Places (explore-view.php) lists real, live places from OpenStreetMap
 * / Google Places. Those upstream sources only expose a handful of fields
 * (name, coordinates, address, phone, opening hours). This service takes a
 * real place (as already returned by ExploreService::getNearbyPlaces) and
 * builds a complete, realistic detail record for it — the extra content a
 * user expects on a place's own page (menus, room types, doctors, reviews,
 * facilities, etc.) that no free live-data API provides.
 *
 * Generation is fully deterministic: the same place (same name + coordinates
 * + category) always produces the same detail record, seeded from a hash of
 * those values. Nothing here touches PHP's global RNG state, so it can't
 * affect session tokens, CSRF values, or anything else that uses mt_rand().
 *
 * Output is category-agnostic on the wire: every place resolves to a common
 * envelope (name, contact info, images, map, rating, reviews) plus a
 * `sections` array. Each section is one of a small set of renderable types
 * (info_grid, list, rooms, menu, doctors, text, reviews, gallery) so the
 * front-end needs exactly one renderer per section type, not one per
 * category — new categories only require a new builder method here, no new
 * client-side code.
 *
 * This file is purely additive: it does not modify ExploreService.php,
 * existing routes, or the database.
 */

final class SeededRng {
    private int $state;

    public function __construct(string $seed) {
        $h = hexdec(substr(md5($seed), 0, 8)) & 0x7fffffff;
        $this->state = $h ?: 1;
    }

    public function next(): int {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7fffffff;
        return $this->state;
    }

    public function int(int $min, int $max): int {
        if ($max <= $min) return $min;
        return $min + ($this->next() % ($max - $min + 1));
    }

    public function float(float $min, float $max, int $dec = 1): float {
        $r = $this->next() / 0x7fffffff;
        return round($min + $r * ($max - $min), $dec);
    }

    public function bool(float $trueProbability = 0.5): bool {
        return ($this->next() / 0x7fffffff) < $trueProbability;
    }

    public function pick(array $arr) {
        return $arr[$this->next() % count($arr)];
    }

    /** Returns $count distinct items from $arr, order shuffled deterministically. */
    public function pickMany(array $arr, int $count): array {
        $arr = array_values($arr);
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = $this->next() % ($i + 1);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }
        return array_slice($arr, 0, min($count, count($arr)));
    }
}

class PlaceDetailsService {

    // ── Shared word pools ──────────────────────────────────────────────
    private const FIRST_NAMES = ['James','Maria','Ahmed','Sara','Wei','Elena','Carlos','Yuki','Fatima','John',
        'Priya','Tom','Anna','Omar','Lucia','David','Aisha','Noah','Emma','Liam','Rina','Hassan','Sophie','Kenji'];
    private const LAST_NAMES  = ['Smith','Garcia','Khan','Muller','Tanaka','Rossi','Silva','Kim','Ahmed','Brown',
        'Sharma','Wilson','Novak','Hassan','Costa','Islam','Chowdhury','Nguyen','Dubois','Petrov'];
    private const STREET_NAMES = ['Riverside','Market','Station','Hilltop','Garden','Harbor','Palm','Central',
        'Sunset','Lakeview','Heritage','Orchard','Meadow','Cedar','Maple'];
    private const STREET_TYPES = ['Street','Road','Avenue','Lane','Boulevard','Way'];
    private const REVIEW_COMMENTS = [
        'Great experience overall, would recommend to anyone visiting the area.',
        'Staff were incredibly helpful and friendly throughout our visit.',
        'Good value for what you get — we would come back again.',
        'Clean, well-maintained, and conveniently located.',
        'Exceeded our expectations, honestly a pleasant surprise.',
        'A solid choice that met all our expectations, nothing more, nothing less.',
        'Loved the atmosphere and the attention to detail.',
        'Quick, professional service and a genuinely welcoming team.',
        'A bit of a hidden gem — glad we stopped by.',
        'Convenient, reliable, and easy to find.',
    ];

    public function build(array $place, string $category): array {
        $name = trim((string)($place['name'] ?? 'Unnamed Place'));
        $lat  = (float)($place['lat'] ?? 0);
        $lng  = (float)($place['lng'] ?? 0);
        $rng  = new SeededRng($category . '|' . $name . '|' . round($lat, 5) . ',' . round($lng, 5));

        $common = $this->buildCommon($place, $category, $rng, $name, $lat, $lng);

        $builderMap = [
            'hotel'         => 'buildHotel',
            'restaurant'    => 'buildRestaurant',
            'attraction'    => 'buildAttraction',
            'tourist_info'  => 'buildTouristInfo',
            'shopping_mall' => 'buildShopping',
            'gas_station'   => 'buildTransportation',
            'hospital'      => 'buildHospital',
            'police'        => 'buildPolice',
            'pharmacy'      => 'buildPharmacy',
            'atm'           => 'buildBank',
        ];
        $method = $builderMap[$category] ?? 'buildGeneric';
        $sections = $this->$method($rng, $name);

        // Reviews are added to every category, at the end.
        $sections[] = $this->buildReviewsSection($rng, $common['rating'], $common['review_count']);

        return array_merge($common, ['sections' => $sections]);
    }

    // ════════════════════════════════════════════════════════════════
    // COMMON ENVELOPE
    // ════════════════════════════════════════════════════════════════

    private function buildCommon(array $place, string $category, SeededRng $rng, string $name, float $lat, float $lng): array {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '', strtolower($name))) ?: 'place';

        $address = $place['address'] ?? null;
        if (!$address) {
            $address = $rng->int(1, 999) . ' ' . $rng->pick(self::STREET_NAMES) . ' ' . $rng->pick(self::STREET_TYPES);
        }

        // SAFETY FIX (customer-facing audit): a phone number is fabricated
        // below when OSM doesn't have a real one, for realism on ordinary
        // categories (hotels, restaurants, shops). That's harmless for those,
        // but for 'hospital' and 'police' this field is exactly what a
        // traveler in a real emergency might actually dial — a made-up
        // number that looks real is a genuine safety hazard. Those two
        // categories now keep phone = null when OSM has no real number, and
        // the front-end (renderPlaceDetail in js/explore.js) shows a "Not
        // listed" fallback plus real emergency-number guidance instead of
        // ever inventing a specific line for them (buildHospital()/
        // buildPolice() below already point people to a real generic
        // emergency number rather than a fabricated one).
        $phone = $place['phone'] ?? null;
        if (!$phone && !in_array($category, ['hospital', 'police'], true)) {
            $phone = '+' . $rng->int(1, 99) . ' ' . $rng->int(1000000000, 1999999999);
        }

        $email   = $place['email'] ?? ($slug . '@' . $slug . '.com');
        $website = $place['website'] ?? ('https://www.' . $slug . '.com');

        $ratingBase = $place['rating'] ?? null;
        $rating = $ratingBase !== null ? (float)$ratingBase : $rng->float(3.6, 4.9, 1);
        $reviewCount = $rng->int(24, 890);

        $openingHours = $place['opening_hours'] ?? $this->guessOpeningHours($rng, $category);
        $isOpenNow = $this->isLikelyOpenNow($openingHours, $rng);

        $imageCount = 5;
        $images = [];
        for ($i = 0; $i < $imageCount; $i++) {
            $images[] = 'https://picsum.photos/seed/' . $slug . '-' . $i . '/700/460';
        }

        $distanceKm = isset($place['distance_m']) && $place['distance_m'] !== null
            ? round($place['distance_m'] / 1000, 1) : null;

        return [
            'name'          => $name,
            'category'      => $category,
            'category_label'=> $this->categoryLabel($category),
            'address'       => $address,
            'phone'         => $phone,
            'email'         => $email,
            'website'       => $website,
            'social'        => [
                'facebook'  => 'https://facebook.com/' . $slug,
                'instagram' => 'https://instagram.com/' . $slug,
            ],
            'map_url'       => "https://www.google.com/maps?q={$lat},{$lng}",
            'map_embed_url' => "https://maps.google.com/maps?q={$lat},{$lng}&z=15&output=embed",
            'lat'           => $lat,
            'lng'           => $lng,
            'distance_km'   => $distanceKm,
            'rating'        => round($rating, 1),
            'review_count'  => $reviewCount,
            'opening_hours' => $openingHours,
            'is_open_now'   => $isOpenNow,
            'hero_image'    => $images[0],
            'images'        => $images,
        ];
    }

    private function categoryLabel(string $category): string {
        return [
            'hotel' => 'Hotel', 'restaurant' => 'Restaurant', 'attraction' => 'Tourist Attraction',
            'tourist_info' => 'Tourist Information Center', 'shopping_mall' => 'Shopping', 'gas_station' => 'Fuel & Transport Service',
            'hospital' => 'Hospital', 'police' => 'Police Station', 'pharmacy' => 'Pharmacy', 'atm' => 'Bank / ATM',
        ][$category] ?? 'Place';
    }

    private function guessOpeningHours(SeededRng $rng, string $category): string {
        if (in_array($category, ['hospital', 'police'], true)) return 'Open 24 hours';
        if ($category === 'gas_station' && $rng->bool(0.5)) return 'Open 24 hours';
        $open = $rng->pick(['7:00 AM', '8:00 AM', '9:00 AM', '10:00 AM']);
        $close = $rng->pick(['6:00 PM', '8:00 PM', '9:00 PM', '10:00 PM', '11:00 PM']);
        return "$open – $close, Daily";
    }

    private function isLikelyOpenNow(string $openingHours, SeededRng $rng): bool {
        if (stripos($openingHours, '24 hour') !== false) return true;
        return $rng->bool(0.72);
    }

    private function buildReviewsSection(SeededRng $rng, float $rating, int $reviewCount): array {
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = [
                'name'    => $rng->pick(self::FIRST_NAMES) . ' ' . substr($rng->pick(self::LAST_NAMES), 0, 1) . '.',
                'rating'  => max(1, min(5, $rng->int((int)round($rating) - 1, 5))),
                'comment' => $rng->pick(self::REVIEW_COMMENTS),
                'date'    => $rng->pick(['2 weeks ago', '1 month ago', '3 months ago', '5 months ago', 'Last week']),
            ];
        }
        return ['type' => 'reviews', 'title' => "Reviews & Ratings ({$reviewCount})", 'icon' => 'fa-star', 'items' => $items];
    }

    // ════════════════════════════════════════════════════════════════
    // CATEGORY BUILDERS — each returns an array of "sections"
    // ════════════════════════════════════════════════════════════════

    private function buildHotel(SeededRng $rng, string $name): array {
        $stars = $rng->int(3, 5);
        $basePrice = $rng->int(45, 90) + ($stars - 3) * 60;

        $roomPool = ['Standard Room', 'Deluxe Room', 'Superior Room', 'Executive Suite', 'Family Room', 'Junior Suite', 'Twin Room'];
        $rooms = [];
        foreach ($rng->pickMany($roomPool, $rng->int(3, 5)) as $i => $room) {
            $rooms[] = [
                'name'  => $room,
                'price' => '$' . ($basePrice + $i * $rng->int(15, 35)) . ' / night',
                'desc'  => $rng->pick(['Sleeps 2 adults, city view', 'Sleeps 2–3, includes balcony', 'Sleeps 4, family-friendly layout',
                                        'Sleeps 2, includes complimentary breakfast', 'Sleeps 2, extra workspace']),
            ];
        }

        $facilities = $rng->pickMany(['Free Wi-Fi', 'Swimming Pool', 'Fitness Center', 'Spa & Wellness', '24-Hour Front Desk',
            'Free Parking', 'Airport Shuttle', 'On-site Restaurant', 'Bar / Lounge', 'Room Service', 'Business Center',
            'Laundry Service', 'Non-Smoking Rooms', 'Air Conditioning', 'Elevator', 'Pet Friendly', 'Breakfast Included',
            'Conference Rooms'], $rng->int(8, 11));

        $services = $rng->pickMany(['Airport Pickup', 'Concierge Desk', 'Currency Exchange', 'Tour Desk', 'Car Rental', 'Babysitting on Request'], 3);

        return [
            ['type' => 'info_grid', 'title' => 'Overview', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-star', 'label' => 'Star Rating', 'value' => str_repeat('★', $stars) . str_repeat('☆', 5 - $stars)],
                ['icon' => 'fa-tag', 'label' => 'Price per Night (from)', 'value' => '$' . $basePrice],
                ['icon' => 'fa-sign-in-alt', 'label' => 'Check-in', 'value' => '2:00 PM'],
                ['icon' => 'fa-sign-out-alt', 'label' => 'Check-out', 'value' => '11:00 AM'],
            ]],
            ['type' => 'text', 'title' => 'Description', 'icon' => 'fa-align-left',
                'content' => "$name is a $stars-star property offering comfortable, well-appointed rooms, attentive service and a convenient location for exploring the surrounding area. Ideal for both leisure and business travelers."],
            ['type' => 'rooms', 'title' => 'Available Room Types', 'icon' => 'fa-bed', 'items' => $rooms],
            ['type' => 'list', 'title' => 'Facilities & Amenities', 'icon' => 'fa-concierge-bell', 'items' => $facilities],
            ['type' => 'list', 'title' => 'Available Services', 'icon' => 'fa-hands-helping', 'items' => $services],
            ['type' => 'text', 'title' => 'Booking Information', 'icon' => 'fa-calendar-check',
                'content' => 'Free cancellation up to 24 hours before check-in. Pay at the property or reserve online with a card. Early check-in and late check-out available on request, subject to availability.'],
        ];
    }

    private function buildRestaurant(SeededRng $rng, string $name): array {
        $cuisine = $rng->pick(['Italian', 'Japanese', 'Thai', 'Indian', 'Mexican', 'French', 'Mediterranean', 'Chinese', 'American', 'Local / Traditional', 'Seafood']);

        $starters = [['Garden Salad', 5], ['Soup of the Day', 4], ['Spring Rolls', 6], ['Bruschetta', 5.5], ['Chicken Wings', 7], ['Stuffed Mushrooms', 6.5]];
        $mains = [['Grilled Chicken Breast', 14], ['Pan-Seared Salmon', 18], ['Vegetable Stir Fry', 11, true], ['Beef Steak', 22],
                  ['Margherita Pizza', 12, true], ['Shrimp Pasta', 16], ['Lentil Curry', 10, true], ['Lamb Chops', 21]];
        $desserts = [['Chocolate Lava Cake', 6], ['Cheesecake', 5.5], ['Fruit Sorbet', 4.5, true], ['Tiramisu', 6]];
        $drinks = [['Fresh Lemonade', 3, true], ['Iced Tea', 3, true], ['House Wine (glass)', 7], ['Craft Beer', 6], ['Espresso', 2.5, true]];

        $mkItems = function (array $rows, SeededRng $rng, bool $popularPossible = true) {
            $out = [];
            foreach ($rows as $i => $row) {
                $out[] = [
                    'name'       => $row[0],
                    'price'      => '$' . number_format((float)$row[1], 2),
                    'vegetarian' => $row[2] ?? false,
                    'popular'    => $popularPossible && $i === 0 ? true : false,
                ];
            }
            return $out;
        };

        $menu = [
            ['name' => 'Starters', 'items' => $mkItems($starters, $rng)],
            ['name' => 'Main Course', 'items' => $mkItems($mains, $rng)],
            ['name' => 'Desserts', 'items' => $mkItems($desserts, $rng)],
            ['name' => 'Beverages', 'items' => $mkItems($drinks, $rng, false)],
        ];

        $delivery = $rng->bool(0.6);
        $facilities = $rng->pickMany(['Outdoor Seating', 'Air Conditioning', 'Free Wi-Fi', 'Wheelchair Accessible', 'Live Music (weekends)',
            'Private Dining Room', 'Full Bar', 'Parking Available', 'Family Friendly'], 5);

        return [
            ['type' => 'info_grid', 'title' => 'Overview', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-utensils', 'label' => 'Cuisine Type', 'value' => $cuisine],
                ['icon' => 'fa-truck', 'label' => 'Delivery Available', 'value' => $delivery ? 'Yes' : 'No'],
                ['icon' => 'fa-chair', 'label' => 'Table Reservations', 'value' => 'Recommended for dinner & weekends'],
            ]],
            ['type' => 'text', 'title' => 'Description', 'icon' => 'fa-align-left',
                'content' => "$name serves authentic $cuisine cuisine in a welcoming setting, using fresh ingredients and recipes rooted in tradition. A favorite stop for both locals and visitors."],
            ['type' => 'menu', 'title' => 'Menu', 'icon' => 'fa-book-open', 'categories' => $menu],
            ['type' => 'list', 'title' => 'Facilities', 'icon' => 'fa-concierge-bell', 'items' => $facilities],
            ['type' => 'text', 'title' => 'Reservations & Delivery', 'icon' => 'fa-calendar-check',
                'content' => 'Call ahead to reserve a table, especially on weekends and holidays. ' . ($delivery
                    ? 'Delivery is available through the restaurant directly or partner delivery apps.'
                    : 'This restaurant currently offers dine-in and takeaway only — no delivery.')],
        ];
    }

    private function buildAttraction(SeededRng $rng, string $name): array {
        $entryFee = $rng->pick([0, 0, 3, 5, 8, 10, 15, 20]);
        $duration = $rng->pick(['30–45 minutes', '1–2 hours', '2–3 hours', 'Half a day']);
        $bestTime = $rng->pick(['Early morning (7–9 AM) to avoid crowds', 'Late afternoon for the best light and photos',
            'Weekday mornings, when it is quietest', 'Around sunset for a memorable view']);

        $thingsToDo = $rng->pickMany(['Guided walking tour', 'Photography spots', 'Local souvenir shopping', 'On-site café / refreshments',
            'Audio guide tour', 'Cultural exhibits', 'Viewpoint / lookout point', 'Garden or courtyard walk'], 4);
        $tips = $rng->pickMany(['Arrive early to beat the crowds', 'Wear comfortable walking shoes', 'Carry water, especially in summer',
            'Photography may be restricted in some areas — check signage', 'Combine with nearby attractions to save travel time',
            'Book tickets online in advance during peak season'], 3);

        return [
            ['type' => 'info_grid', 'title' => 'Visitor Information', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-ticket-alt', 'label' => 'Entry Fee', 'value' => $entryFee === 0 ? 'Free' : ('$' . $entryFee)],
                ['icon' => 'fa-hourglass-half', 'label' => 'Estimated Visit Duration', 'value' => $duration],
                ['icon' => 'fa-clock', 'label' => 'Best Time to Visit', 'value' => $bestTime],
            ]],
            ['type' => 'text', 'title' => 'About', 'icon' => 'fa-align-left',
                'content' => "$name is one of the notable points of interest in the area, drawing visitors for its character, atmosphere and cultural significance."],
            ['type' => 'text', 'title' => 'History', 'icon' => 'fa-landmark',
                'content' => "Over the years, $name has become a well-known local landmark, shaped by the surrounding community and its traditions. It reflects the character of the destination and remains a popular stop for travelers wanting to understand the area's heritage."],
            ['type' => 'list', 'title' => 'Things to Do', 'icon' => 'fa-map-signs', 'items' => $thingsToDo],
            ['type' => 'list', 'title' => 'Visitor Tips', 'icon' => 'fa-lightbulb', 'items' => $tips],
        ];
    }

    private function buildTouristInfo(SeededRng $rng, string $name): array {
        $languages = $rng->pickMany(['English', 'Spanish', 'French', 'Mandarin', 'Japanese', 'Arabic', 'German', 'Local language'], 3);
        $services = $rng->pickMany(['Free Maps & Brochures', 'Guided Tour Booking', 'Hotel Booking Assistance', 'Currency Exchange Desk',
            'Luggage Storage', 'Public Transport Information', 'Lost & Found'], 4);
        return [
            ['type' => 'text', 'title' => 'About', 'icon' => 'fa-align-left',
                'content' => "$name helps travelers get oriented — offering maps, local recommendations, and booking assistance so visitors can make the most of their time in the area."],
            ['type' => 'list', 'title' => 'Services Offered', 'icon' => 'fa-concierge-bell', 'items' => $services],
            ['type' => 'list', 'title' => 'Languages Spoken', 'icon' => 'fa-language', 'items' => $languages],
        ];
    }

    private function buildShopping(SeededRng $rng, string $name): array {
        $categories = $rng->pickMany(['Clothing & Apparel', 'Electronics', 'Handicrafts & Souvenirs', 'Jewelry', 'Footwear',
            'Books & Stationery', 'Home Décor', 'Local Produce & Groceries'], $rng->int(3, 5));
        $priceRange = $rng->pick(['$ (Budget-friendly)', '$$ (Mid-range)', '$$$ (Upscale)']);

        return [
            ['type' => 'info_grid', 'title' => 'Overview', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-tags', 'label' => 'Price Range', 'value' => $priceRange],
            ]],
            ['type' => 'text', 'title' => 'Description', 'icon' => 'fa-align-left',
                'content' => "$name offers a varied selection of goods, popular with both locals and visitors looking for quality products at fair prices."],
            ['type' => 'list', 'title' => 'Product Categories', 'icon' => 'fa-shopping-bag', 'items' => $categories],
        ];
    }

    private function buildTransportation(SeededRng $rng, string $name): array {
        $fuels = [];
        foreach ($rng->pickMany(['Petrol / Octane', 'Diesel', 'CNG', 'Premium'], $rng->int(2, 4)) as $f) {
            $fuels[] = $f . ' — $' . $rng->float(0.9, 1.9, 2) . '/liter';
        }
        $evCharging = $rng->bool(0.35);
        if ($evCharging) $fuels[] = 'EV Fast Charging — $0.25–0.40/kWh';

        $services = $rng->pickMany(['Car Wash', 'Tire Air & Repair', 'Convenience Store', 'ATM on Site', 'Restrooms', 'Vehicle Inspection'], 4);
        $vehicles = $rng->pickMany(['Cars', 'Motorcycles', 'Buses', 'Trucks', 'Auto-rickshaws'], 3);

        return [
            ['type' => 'info_grid', 'title' => 'Overview', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-car', 'label' => 'Vehicle Types Served', 'value' => implode(', ', $vehicles)],
            ]],
            ['type' => 'list', 'title' => 'Fuel & Charging', 'icon' => 'fa-gas-pump', 'items' => $fuels],
            ['type' => 'list', 'title' => 'Additional Services', 'icon' => 'fa-tools', 'items' => $services],
            ['type' => 'text', 'title' => 'Booking Information', 'icon' => 'fa-info-circle',
                'content' => 'Walk-in service — no reservation required for fuel or basic services. Fleet and business accounts are available on request.'],
        ];
    }

    private function buildHospital(SeededRng $rng, string $name): array {
        $departments = $rng->pickMany(['Emergency Medicine', 'Cardiology', 'Pediatrics', 'Orthopedics', 'General Surgery',
            'Gynecology & Obstetrics', 'Neurology', 'Dermatology', 'ENT', 'Radiology', 'Internal Medicine', 'Dental'], $rng->int(5, 8));

        $doctors = [];
        for ($i = 0; $i < 4; $i++) {
            $doctors[] = [
                'name'       => 'Dr. ' . $rng->pick(self::FIRST_NAMES) . ' ' . $rng->pick(self::LAST_NAMES),
                'specialty'  => $rng->pick($departments),
                'department' => $rng->pick($departments),
            ];
        }

        // SAFETY FIX: this used to fabricate a venue-specific "Emergency
        // Contact" phone number that looked completely real. A tourist in an
        // actual emergency could dial it and reach no one. Replaced with the
        // same kind of real, generic guidance buildPolice() already gives
        // below, instead of a made-up number for this specific hospital.
        return [
            ['type' => 'info_grid', 'title' => 'Emergency Information', 'icon' => 'fa-truck-medical', 'items' => [
                ['icon' => 'fa-phone', 'label' => 'In an Emergency', 'value' => 'Call your local emergency number (e.g. 911 / 112 / 999) or the emergency line on your travel insurance card — not a number listed here'],
                ['icon' => 'fa-ambulance', 'label' => 'Ambulance Service', 'value' => 'Available 24/7 — dispatch on call'],
                ['icon' => 'fa-notes-medical', 'label' => 'Emergency Services', 'value' => 'Yes, round-the-clock'],
            ]],
            ['type' => 'list', 'title' => 'Departments', 'icon' => 'fa-hospital', 'items' => $departments],
            ['type' => 'doctors', 'title' => 'Doctors', 'icon' => 'fa-user-md', 'items' => $doctors],
        ];
    }

    private function buildPolice(SeededRng $rng, string $name): array {
        $officer = $rng->pick(['Inspector', 'Sub-Inspector', 'Officer-in-Charge', 'Sergeant']) . ' '
            . $rng->pick(self::FIRST_NAMES) . ' ' . $rng->pick(self::LAST_NAMES);

        return [
            ['type' => 'info_grid', 'title' => 'Station Information', 'icon' => 'fa-shield-alt', 'items' => [
                ['icon' => 'fa-phone-volume', 'label' => 'Emergency Number', 'value' => '112 (or local emergency line)'],
                ['icon' => 'fa-user-tie', 'label' => 'Officer-in-Charge', 'value' => $officer],
                ['icon' => 'fa-clock', 'label' => 'Working Hours', 'value' => 'Open 24 hours'],
            ]],
            ['type' => 'text', 'title' => 'About', 'icon' => 'fa-align-left',
                'content' => "$name handles local law enforcement, public safety, and emergency response for the surrounding area. Front-desk services are available for reporting non-emergencies."],
        ];
    }

    private function buildPharmacy(SeededRng $rng, string $name): array {
        $emergency = $rng->bool(0.4);
        return [
            ['type' => 'info_grid', 'title' => 'Overview', 'icon' => 'fa-info-circle', 'items' => [
                ['icon' => 'fa-clock', 'label' => 'Emergency / Night Availability', 'value' => $emergency ? 'Yes, 24-hour service' : 'Standard hours only'],
            ]],
            ['type' => 'text', 'title' => 'About', 'icon' => 'fa-align-left',
                'content' => "$name stocks a wide range of prescription and over-the-counter medication, along with basic health and wellness products."],
        ];
    }

    private function buildBank(SeededRng $rng, string $name): array {
        $branchType = $rng->pick(['Main Branch', 'Sub-Branch', 'ATM Booth Only', 'Full-Service Branch']);
        $services = $rng->pickMany(['Cash Withdrawal', 'Cash Deposit', 'Fund Transfer', 'Currency Exchange', 'Locker Service', 'Cheque Deposit'], 4);
        $atmAvailable = $rng->bool(0.85);

        return [
            ['type' => 'info_grid', 'title' => 'Branch Information', 'icon' => 'fa-university', 'items' => [
                ['icon' => 'fa-building', 'label' => 'Branch Type', 'value' => $branchType],
                ['icon' => 'fa-money-bill-wave', 'label' => 'ATM Availability', 'value' => $atmAvailable ? 'Yes, on-site' : 'No ATM at this location'],
            ]],
            ['type' => 'list', 'title' => 'Services', 'icon' => 'fa-hand-holding-usd', 'items' => $services],
        ];
    }

    private function buildGeneric(SeededRng $rng, string $name): array {
        return [
            ['type' => 'text', 'title' => 'About', 'icon' => 'fa-align-left',
                'content' => "$name is a nearby point of interest. Contact details and directions are listed above."],
        ];
    }
}
