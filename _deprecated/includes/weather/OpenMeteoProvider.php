<?php
require_once __DIR__ . '/WeatherProviderInterface.php';

/**
 * OpenMeteoProvider — Round 89 (items 41/71). Extracted verbatim from the
 * old `tms_get_weather_openmeteo()` free function; behavior is unchanged,
 * only the caching, negative-caching, and `tms_log_system_event` telemetry
 * moved out to the orchestrator (`tms_get_weather()` in backend/config.php),
 * since none of those are Open-Meteo-specific. The WMO-code-to-text/icon
 * helpers (formerly free functions `tms_openmeteo_code_to_text()` /
 * `tms_openmeteo_code_to_icon()`, used nowhere else in the codebase) moved
 * in here as private methods -- this is the only place that ever called
 * them.
 *
 * Free, no-signup-required weather -- same provider and request shape
 * already proven in includes/ExploreService.php, adapted to return the
 * field names tms_get_weather()'s callers expect. Icons come back as
 * Font-Awesome class names (e.g. "fa-cloud-sun") rather than
 * OpenWeatherMap's icon codes (e.g. "01d") -- callers must check `source`
 * before deciding whether to render an <img> (OWM) or an <i> (this).
 */
class OpenMeteoProvider implements WeatherProviderInterface {

    public function name(): string {
        return 'open-meteo';
    }

    /** No API key or signup required -- always available. */
    public function isConfigured(): bool {
        return true;
    }

    public function fetch(float $lat, float $lng, string $label): array {
        try {
            $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
                'latitude'      => $lat,
                'longitude'     => $lng,
                'current'       => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
                'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset',
                'forecast_days' => 6,
                'timezone'      => 'auto',
            ]);
            $json = _tms_http_get($url);
            $w = $json ? json_decode($json, true) : null;
            // A partial payload can have `current` present but no
            // `temperature_2m` -- checking only the former used to return
            // ok:true with temp=null, rendering blank/fabricated numbers.
            if (!$w || !isset($w['current']) || !isset($w['current']['temperature_2m'])) {
                return ['ok' => false, 'error' => 'empty/malformed current-weather response'];
            }

            $code    = (int)($w['current']['weather_code'] ?? 0);
            $isDay   = (bool)($w['current']['is_day'] ?? 1);

            $forecast = [];
            if (!empty($w['daily']['time'])) {
                foreach ($w['daily']['time'] as $i => $date) {
                    if ($i === 0) continue; // skip today, matches the OWM path (starts from tomorrow)
                    $forecast[] = [
                        'date'      => $date,
                        'day_label' => date('D', strtotime($date)),
                        'temp_max'  => isset($w['daily']['temperature_2m_max'][$i]) ? round($w['daily']['temperature_2m_max'][$i]) : null,
                        'temp_min'  => isset($w['daily']['temperature_2m_min'][$i]) ? round($w['daily']['temperature_2m_min'][$i]) : null,
                        'condition' => $this->codeToText((int)($w['daily']['weather_code'][$i] ?? 0)),
                        'icon'      => $this->codeToIcon((int)($w['daily']['weather_code'][$i] ?? 0), true),
                    ];
                    if (count($forecast) >= 5) break;
                }
            }

            return [
                'ok'          => true,
                'source'      => $this->name(),
                'city'        => $label,
                'temp'        => isset($w['current']['temperature_2m']) ? round($w['current']['temperature_2m']) : null,
                'feels_like'  => isset($w['current']['apparent_temperature']) ? round($w['current']['apparent_temperature']) : null,
                'humidity'    => $w['current']['relative_humidity_2m'] ?? null,
                'wind_speed'  => isset($w['current']['wind_speed_10m']) ? round($w['current']['wind_speed_10m'] / 3.6, 1) : null, // km/h -> m/s to match OWM units
                'condition'   => $this->codeToText($code),
                'description' => $this->codeToText($code),
                'icon'        => $this->codeToIcon($code, $isDay),
                'sunrise'     => isset($w['daily']['sunrise'][0]) ? date('h:i A', strtotime($w['daily']['sunrise'][0])) : null,
                'sunset'      => isset($w['daily']['sunset'][0])  ? date('h:i A', strtotime($w['daily']['sunset'][0]))  : null,
                'forecast'    => $forecast,
                'fetched_at'  => date('c'),
            ];
        } catch (Throwable $e) {
            error_log('[TMS Weather] Open-Meteo path failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** WMO weather code -> human text. */
    private function codeToText(int $code): string {
        $map = [
            0=>'Clear sky',1=>'Mainly clear',2=>'Partly cloudy',3=>'Overcast',
            45=>'Fog',48=>'Depositing rime fog',
            51=>'Light drizzle',53=>'Moderate drizzle',55=>'Dense drizzle',
            56=>'Light freezing drizzle',57=>'Dense freezing drizzle',
            61=>'Slight rain',63=>'Moderate rain',65=>'Heavy rain',
            66=>'Light freezing rain',67=>'Heavy freezing rain',
            71=>'Slight snow fall',73=>'Moderate snow fall',75=>'Heavy snow fall',77=>'Snow grains',
            80=>'Slight rain showers',81=>'Moderate rain showers',82=>'Violent rain showers',
            85=>'Slight snow showers',86=>'Heavy snow showers',
            95=>'Thunderstorm',96=>'Thunderstorm with hail',99=>'Thunderstorm with heavy hail',
        ];
        return $map[$code] ?? 'Clear';
    }

    /** WMO weather code -> Font Awesome icon class (matches ExploreService.php's mapping). */
    private function codeToIcon(int $code, bool $isDay): string {
        if (in_array($code, [0, 1], true)) return $isDay ? 'fa-sun' : 'fa-moon-o';
        if ($code === 2) return $isDay ? 'fa-cloud' : 'fa-cloud';
        if ($code === 3) return 'fa-cloud';
        if (in_array($code, [45, 48], true)) return 'fa-align-justify'; // fog — no dedicated FA glyph in the FA4 set this project uses
        if (in_array($code, [51,53,55,56,57,61,63,65,66,67,80,81,82], true)) return 'fa-tint';
        if (in_array($code, [71,73,75,77,85,86], true)) return 'fa-snowflake-o';
        if (in_array($code, [95,96,99], true)) return 'fa-bolt';
        return 'fa-cloud';
    }
}
