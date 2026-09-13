<?php
require_once __DIR__ . '/WeatherProviderInterface.php';

/**
 * OpenWeatherMapProvider — Round 89 (items 41/71). Extracted verbatim from
 * `tms_get_weather()`'s old inline OWM branch; behavior is unchanged, only
 * the caching, negative-caching, fallback-to-Open-Meteo, and
 * `tms_log_system_event` telemetry moved out to the orchestrator
 * (`tms_get_weather()` in backend/config.php), since none of those are
 * OpenWeatherMap-specific.
 */
class OpenWeatherMapProvider implements WeatherProviderInterface {

    public function name(): string {
        return 'openweathermap';
    }

    public function isConfigured(): bool {
        return !empty(OPENWEATHER_API_KEY);
    }

    public function fetch(float $lat, float $lng, string $label): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        try {
            $curBase = 'https://api.openweathermap.org/data/2.5/weather';
            $curUrl  = $curBase . '?lat=' . urlencode((string)$lat) . '&lon=' . urlencode((string)$lng)
                     . '&units=metric&appid=' . urlencode(OPENWEATHER_API_KEY);

            $fcBase = 'https://api.openweathermap.org/data/2.5/forecast';
            $fcUrl  = $fcBase . '?lat=' . urlencode((string)$lat) . '&lon=' . urlencode((string)$lng)
                    . '&units=metric&appid=' . urlencode(OPENWEATHER_API_KEY);

            $curJson = _tms_http_get($curUrl);
            $cur = $curJson ? json_decode($curJson, true) : null;

            if (!$cur || !isset($cur['main'])) {
                return ['ok' => false, 'error' => 'empty/malformed current-weather response'];
            }

            $forecast = [];
            $fcJson = _tms_http_get($fcUrl);
            $fc = $fcJson ? json_decode($fcJson, true) : null;
            // Bucket forecast slots in the DESTINATION's own UTC offset
            // (OWM returns it as `timezone`), not the server's -- otherwise
            // the daily highs/lows blend two local days for any destination
            // in a different zone (fixed originally in Round 14).
            $fcTzOffset = (int)($cur['timezone'] ?? 0);
            $localToday = gmdate('Y-m-d', time() + $fcTzOffset);
            if ($fc && !empty($fc['list'])) {
                $byDay = [];
                foreach ($fc['list'] as $slot) {
                    if (empty($slot['dt'])) { continue; }
                    $day = gmdate('Y-m-d', (int)$slot['dt'] + $fcTzOffset);
                    $byDay[$day][] = $slot;
                }
                foreach ($byDay as $day => $slots) {
                    if ($day === $localToday) { continue; } // skip the partial current day
                    // array_column silently drops slots with no 'main', so this
                    // can legitimately come back empty on a degraded payload --
                    // max([])/min([]) throw a ValueError (an Error, not an
                    // Exception), so this must be checked before calling them.
                    $temps = array_filter(array_column(array_column($slots, 'main'), 'temp'), 'is_numeric');
                    if (!$temps) { continue; }
                    $midSlot = $slots[intval(count($slots) / 2)];
                    $forecast[] = [
                        'date'      => $day,
                        'day_label' => date('D', strtotime($day)),
                        'temp_max'  => round(max($temps)),
                        'temp_min'  => round(min($temps)),
                        'condition' => $midSlot['weather'][0]['main'] ?? '',
                        'icon'      => $midSlot['weather'][0]['icon'] ?? '01d',
                    ];
                    if (count($forecast) >= 5) break;
                }
            }

            // sys.sunrise/sunset are UTC unix timestamps; apply the same
            // destination offset to show local time, not the server's.
            $tzOffset = (int)($cur['timezone'] ?? 0);
            $sunrise  = isset($cur['sys']['sunrise']) ? gmdate('h:i A', $cur['sys']['sunrise'] + $tzOffset) : null;
            $sunset   = isset($cur['sys']['sunset'])  ? gmdate('h:i A', $cur['sys']['sunset']  + $tzOffset) : null;

            return [
                'ok'          => true,
                'source'      => $this->name(),
                'city'        => ($cur['name'] ?? '') ?: $label,
                'temp'        => round($cur['main']['temp'] ?? 0),
                'feels_like'  => round($cur['main']['feels_like'] ?? ($cur['main']['temp'] ?? 0)),
                'humidity'    => $cur['main']['humidity'] ?? null,
                'wind_speed'  => round($cur['wind']['speed'] ?? 0, 1),
                'condition'   => $cur['weather'][0]['main'] ?? 'Clear',
                'description' => ucfirst($cur['weather'][0]['description'] ?? ''),
                'icon'        => $cur['weather'][0]['icon'] ?? '01d',
                'sunrise'     => $sunrise,
                'sunset'      => $sunset,
                'forecast'    => $forecast,
                'fetched_at'  => date('c'),
            ];
        } catch (Throwable $e) {
            // Even an unexpected error falls back to the orchestrator's next
            // provider rather than surfacing a fatal error to the visitor.
            error_log('[TMS Weather] OpenWeatherMap path failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
