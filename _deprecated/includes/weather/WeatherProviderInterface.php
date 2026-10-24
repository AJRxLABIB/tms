<?php
/**
 * WeatherProviderInterface.php — Round 89 (master prompt items 41/71:
 * "Provider Abstraction" / architecture).
 *
 * `tms_get_weather()` (backend/config.php) is the single entry point every
 * caller (frontend/weather.php, frontend/compare.php,
 * admin/operations-dashboard.php, ...) has ever used for live weather. Before
 * this round it hardcoded OpenWeatherMap's exact two-endpoint request/
 * response shape directly inline, with the Open-Meteo fallback bolted on as
 * a second, separately hardcoded code path inside the same function --
 * exactly the shape this project's own AIProviderInterface /
 * PaymentProviderInterface pattern (backend/ai/providers,
 * backend/payments) already exists to avoid elsewhere.
 *
 * `tms_get_weather()` still owns everything that does NOT vary by
 * provider -- coordinate validation, the shared cache, negative-caching on
 * failure, and system-health telemetry (`tms_log_system_event`, which feeds
 * admin/system-health.php's "Weather" line, item 56) -- and now delegates
 * only the actual HTTP fetch + response normalization to a small object
 * implementing this interface. Adding a third provider, or reordering
 * priority, means writing one new class here and touching one line in
 * `tms_get_weather()` -- never any of its callers.
 *
 * Every implementation MUST NOT throw -- catch your own failures internally
 * and return ['ok' => false, 'error' => ...] so `tms_get_weather()` can fall
 * back to the next provider, exactly as it always has.
 */
interface WeatherProviderInterface {

    /**
     * Machine name stored in the result's `source` field, e.g.
     * 'openweathermap', 'open-meteo'. Existing callers (weather.php's
     * "Powered by ..." badge, tblweathercache-adjacent logging) already key
     * off these exact strings -- do not rename existing values when adding
     * a provider.
     */
    public function name(): string;

    /**
     * True if this provider has what it needs to run (an API key, etc).
     * Open-Meteo requires no signup/key and is therefore always configured.
     */
    public function isConfigured(): bool;

    /**
     * Fetch current weather + up to 5 days of forecast for one coordinate.
     * The caller has already validated $lat/$lng are in-range, non-null
     * coordinates -- providers do not need to re-check that.
     *
     * @param float  $lat   Latitude, already range-checked by the caller.
     * @param float  $lng   Longitude, already range-checked by the caller.
     * @param string $label Fallback display name if the provider's own
     *                      response has no place name (e.g. Open-Meteo,
     *                      which never returns one).
     *
     * @return array{
     *   ok: bool, source?: string, city?: string, temp?: ?int,
     *   feels_like?: ?int, humidity?: ?int, wind_speed?: ?float,
     *   condition?: string, description?: string, icon?: string,
     *   sunrise?: ?string, sunset?: ?string, forecast?: array,
     *   fetched_at?: string, error?: string
     * }
     */
    public function fetch(float $lat, float $lng, string $label): array;
}
