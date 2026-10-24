/* tms-weather-tips.js — weather-aware suggestions computed ONLY from the numbers in the forecast object.
   tmsWeatherTips(weather) -> [{ level: 'info'|'warn', text: string, action: null | {type, label, categories?} }]
   Input shape = api/explore.php?action=weather -> data.weather: { daily:[{date,tmax,tmin,pop,wind}], current:{temp,wind_speed}, alerts? }.
   Missing / null numbers are skipped (never treated as 0). No official alerts are invented. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.TmsWeatherTips = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  var T = { rainPop: 60, heatC: 35, windKmh: 45, windDailyKmh: 50, heavyRainMm: 20, uvHigh: 8 };
  // Categories the places provider really supports that are normally indoors.
  var INDOOR = ['shopping_mall', 'restaurant', 'tourist_info', 'hotel'];

  function num(v) { return (typeof v === 'number' && isFinite(v)) ? v : (v != null && v !== '' && isFinite(Number(v)) ? Number(v) : null); }
  function label(d) { return d && d.date ? String(d.date) : 'a forecast day'; }

  function tmsWeatherTips(w) {
    var out = [];
    if (!w || typeof w !== 'object') return out;
    var daily = Array.isArray(w.daily) ? w.daily : [];

    var rainy = daily.filter(function (d) { var p = num(d && d.pop); return p !== null && p >= T.rainPop; });
    if (rainy.length) {
      var top = rainy.reduce(function (a, b) { return num(b.pop) > num(a.pop) ? b : a; });
      out.push({ level: 'warn',
        text: 'Rain probability is ' + num(top.pop) + '% on ' + label(top) + (rainy.length > 1 ? ' (and ' + (rainy.length - 1) + ' other day' + (rainy.length > 2 ? 's' : '') + ' at ' + T.rainPop + '% or more)' : '') + '. Outdoor plans may be disrupted.',
        action: { type: 'filter-nearby', label: 'Show indoor-friendly places', categories: INDOOR.slice() } });
    }

    var hot = daily.filter(function (d) { var t = num(d && d.tmax); return t !== null && t >= T.heatC; });
    var curT = w.current ? num(w.current.temp) : null;
    if (hot.length || (curT !== null && curT >= T.heatC)) {
      var hd = hot[0];
      out.push({ level: 'warn',
        text: (hd ? 'Maximum temperature reaches ' + Math.round(num(hd.tmax)) + '°C on ' + label(hd) : 'It is currently ' + Math.round(curT) + '°C') + '. General heat advice (not an official warning): drink water often, avoid the midday sun and rest in shade or air-conditioning.',
        action: null });
    }

    var curW = w.current ? num(w.current.wind_speed) : null;
    var windy = daily.filter(function (d) { var x = num(d && d.wind); return x !== null && x >= T.windDailyKmh; });
    if ((curW !== null && curW >= T.windKmh) || windy.length) {
      var wv = curW !== null && curW >= T.windKmh ? curW : num(windy[0].wind);
      out.push({ level: 'warn', text: 'Wind up to ' + Math.round(wv) + ' km/h in the forecast. Conditions may affect outdoor activities, boats and viewpoints.', action: null });
    }

    // Real precipitation totals (mm) and UV index, when the forecast response carries them (Open-Meteo precipitation_sum, uv_index_max).
    var heavy = daily.filter(function (d) { var m = num(d && d.precip_mm); return m !== null && m >= T.heavyRainMm; });
    if (heavy.length) {
      var hv = heavy.reduce(function (x, y) { return num(y.precip_mm) > num(x.precip_mm) ? y : x; });
      out.push({ level: 'warn', text: 'About ' + Math.round(num(hv.precip_mm)) + ' mm of rain is forecast on ' + label(hv) + '. Roads and boat trips may be delayed, so allow extra time.', action: null });
    }
    var uv = daily.filter(function (d) { var u = num(d && d.uv_max); return u !== null && u >= T.uvHigh; });
    if (uv.length) {
      out.push({ level: 'info', text: 'UV index reaches ' + Math.round(num(uv[0].uv_max)) + ' on ' + label(uv[0]) + '. General sun advice: sunscreen, a hat, water, and shade around midday.', action: null });
    }

    // Provider-supplied alerts only (the response's own alerts array, shown as given).
    if (Array.isArray(w.alerts)) w.alerts.forEach(function (a) {
      if (a && typeof a.message === 'string' && a.message) out.push({ level: a.severity === 'warning' ? 'warn' : 'info', text: a.message, action: null, fromProvider: true });
    });

    out.push({ level: 'info', text: 'Forecasts are estimates, not guarantees. Check again close to your travel date.', action: null });
    return out;
  }
  tmsWeatherTips.thresholds = T;
  tmsWeatherTips.INDOOR = INDOOR;
  return tmsWeatherTips;
});
