import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

repls = []

# 1. Insert the "Check Weather Impact" card right after the AI-feedback
#    widget, still inside the "itinerary exists" branch, only for a trip
#    that hasn't finished/been cancelled (replanning a completed or
#    cancelled trip's itinerary makes no sense).
old1 = '''                    <div class="tms-ai-feedback" style="margin-top:6px;font-size:12px;color:#999;">
                        Was this itinerary helpful?
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, 1, this)" style="border:none;background:none;color:#27ae60;cursor:pointer;padding:2px 6px;" title="Yes, helpful"><i class="fa fa-thumbs-up"></i></button>
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, -1, this)" style="border:none;background:none;color:#c0392b;cursor:pointer;padding:2px 6px;" title="No, not helpful"><i class="fa fa-thumbs-down"></i></button>
                    </div>
                <?php else: ?>'''
new1 = '''                    <div class="tms-ai-feedback" style="margin-top:6px;font-size:12px;color:#999;">
                        Was this itinerary helpful?
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, 1, this)" style="border:none;background:none;color:#27ae60;cursor:pointer;padding:2px 6px;" title="Yes, helpful"><i class="fa fa-thumbs-up"></i></button>
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, -1, this)" style="border:none;background:none;color:#c0392b;cursor:pointer;padding:2px 6px;" title="No, not helpful"><i class="fa fa-thumbs-down"></i></button>
                    </div>
                <?php if ($phase === 'before' || $phase === 'during'): ?>
                <?php
                // Round 115 (master prompt item 12, "AI Dynamic Trip
                // Replanning"): pure opt-in check, never automatic. Nothing
                // here changes the saved itinerary or the booking unless the
                // traveler explicitly clicks "Apply" on a proposal they've
                // already read in full -- see api/ai.php's
                // handleWeatherReplan{Check,Preview,Apply}() and
                // ItineraryPlanner::checkWeatherRisk()/saveApprovedItinerary().
                ?>
                <div class="mt-card" id="mtWeatherReplanCard" style="margin-top:16px;">
                    <h4><i class="fa fa-cloud"></i> Weather Impact Check</h4>
                    <p style="font-size:13px;color:#888;margin-bottom:12px;">
                        Checks the real forecast against your saved itinerary and flags any day that might
                        need a change. Nothing changes automatically — you review and approve any suggested
                        update yourself before it's saved.
                    </p>
                    <button type="button" class="mt-generate-btn" id="mtWeatherCheckBtn" onclick="mtCheckWeatherReplan()">
                        <i class="fa fa-cloud"></i> Check Weather Impact on My Itinerary
                    </button>
                    <div id="mtWeatherReplanResult" style="margin-top:14px;"></div>
                </div>
                <?php endif; ?>
                <?php else: ?>'''
repls.append((old1, new1))

# 2. Insert the JS functions right after mtGenerateItinerary()'s closing brace.
old2 = '''        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-magic"></i> Generate an AI Itinerary';
            alert('Network error — please try again.');
        });
}
'''
new2 = '''        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-magic"></i> Generate an AI Itinerary';
            alert('Network error — please try again.');
        });
}

// Round 115 (master prompt item 12, "AI Dynamic Trip Replanning"). Three
// steps, matching the three separate API actions: check (read-only) ->
// preview (generates but does not save) -> apply (saves exactly what was
// previewed, only on an explicit click).
<?php if ($phase === 'before' || $phase === 'during'): ?>
var mtTripData = {
    booking_id: <?php echo (int)$trip->BookingId; ?>,
    destination: <?php echo json_encode($trip->PackageLocation, JSON_UNESCAPED_UNICODE); ?>,
    start_date: <?php echo json_encode((new DateTime($trip->FromDate))->format('Y-m-d')); ?>,
    duration: <?php echo max(1, (new DateTime($trip->FromDate))->diff(new DateTime($trip->ToDate))->days ?: 1); ?>,
    travelers: <?php echo (int)$trip->NoOfPersons; ?>,
    budget: <?php echo (int)round((float)$trip->TotalCost); ?>,
    accommodation: <?php echo json_encode($trip->HotelName ?: 'hotel'); ?>,
    transport: <?php echo json_encode($trip->TransportMode ?: 'mixed'); ?>
};

function mtCsrf() { return (document.querySelector('[name="csrf_token"]') || {}).value || ''; }

function mtCheckWeatherReplan() {
    var btn = document.getElementById('mtWeatherCheckBtn');
    var resultBox = document.getElementById('mtWeatherReplanResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Checking…';
    resultBox.innerHTML = '';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_check', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-cloud"></i> Check Weather Impact on My Itinerary';
            if (!res || !res.success) {
                resultBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">' + ((res && res.error) || 'Could not check weather right now.') + '</p>';
                return;
            }
            var data = res.data;
            if (data.no_forecast_available) {
                resultBox.innerHTML = '<p style="font-size:13px;color:#888;">No live forecast available yet for these dates (forecasts only cover about a week out) — check back closer to your trip.</p>';
            } else if (!data.flagged_days || data.flagged_days.length === 0) {
                resultBox.innerHTML = '<p style="font-size:13px;color:#27ae60;"><i class="fa fa-check-circle"></i> No weather concerns found for your itinerary right now.</p>';
            } else {
                var html = '<p style="font-size:13px;color:#c0392b;font-weight:600;"><i class="fa fa-exclamation-triangle"></i> ' + data.flagged_days.length + ' day(s) may need a look:</p>';
                data.flagged_days.forEach(function (d) {
                    html += '<div class="mt-day" style="border-left-color:#e74c3c;">'
                        + '<h5>Day ' + d.day + ' — ' + d.date + '</h5>'
                        + '<div class="mt-sched-item">Forecast: ' + d.rain_chance + '% chance of rain, ' + d.temp_range + '</div>'
                        + (d.itinerary_title ? '<div class="mt-sched-item">Planned: ' + d.itinerary_title + '</div>' : '')
                        + '<button type="button" class="mt-generate-btn" style="margin-top:8px;font-size:12px;padding:6px 14px;" onclick="mtPreviewReplan(' + d.day + ', \\'' + d.date + '\\', ' + d.rain_chance + ', this)">Suggest an alternative for Day ' + d.day + '</button>'
                        + '<div class="mt-replan-preview" style="margin-top:10px;"></div>'
                        + '</div>';
                });
                resultBox.innerHTML = html;
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-cloud"></i> Check Weather Impact on My Itinerary';
            resultBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">Network error — please try again.</p>';
        });
}

function mtPreviewReplan(day, date, rainChance, btnEl) {
    var previewBox = btnEl.parentElement.querySelector('.mt-replan-preview');
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating proposal…';
    previewBox.innerHTML = '';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration,
        travelers: mtTripData.travelers, budget: mtTripData.budget,
        accommodation: mtTripData.accommodation, transport: mtTripData.transport,
        flagged_day: day, flagged_date: date, rain_chance: rainChance, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_preview', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btnEl.style.display = 'none';
            if (!res || !res.success) {
                previewBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">' + ((res && res.error) || 'Could not generate a proposal right now.') + '</p>';
                return;
            }
            var itin = res.data.itinerary;
            var html = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;">'
                + '<p style="font-size:12px;color:#92400e;font-weight:700;margin-bottom:8px;"><i class="fa fa-info-circle"></i> PROPOSED CHANGE — not saved yet. Review the full itinerary below, then choose.</p>';
            (itin.days || []).forEach(function (d) {
                html += '<div class="mt-day"><h5>Day ' + (d.day || '') + (d.title ? ' — ' + d.title : '') + '</h5>';
                (d.schedule || []).forEach(function (item) {
                    html += '<div class="mt-sched-item"><strong>' + (item.time || '') + '</strong> — ' + (item.activity || '') + (item.location ? ' @ ' + item.location : '') + '</div>';
                });
                html += '</div>';
            });
            html += '<button type="button" class="mt-generate-btn" onclick="mtApplyReplan(this)">Apply This Update</button> '
                + '<button type="button" class="mt-generate-btn" style="background:#aaa;" onclick="this.closest(\\'.mt-replan-preview\\').innerHTML=\\'\\'">Discard</button>'
                + '</div>';
            previewBox.innerHTML = html;
            previewBox.dataset.itinerary = JSON.stringify(itin);
        })
        .catch(function () {
            previewBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">Network error — please try again.</p>';
        });
}

function mtApplyReplan(btnEl) {
    var previewBox = btnEl.closest('.mt-replan-preview');
    var itinJson = previewBox.dataset.itinerary;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration,
        travelers: mtTripData.travelers, budget: mtTripData.budget,
        accommodation: mtTripData.accommodation, transport: mtTripData.transport,
        itinerary: itinJson, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_apply', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                btnEl.disabled = false;
                btnEl.innerHTML = 'Apply This Update';
                alert((res && res.error) || 'Could not save this update. Please try again.');
            }
        })
        .catch(function () {
            btnEl.disabled = false;
            btnEl.innerHTML = 'Apply This Update';
            alert('Network error — please try again.');
        });
}
<?php endif; ?>
'''
repls.append((old2, new2))

missing = []
for i, (old, new) in enumerate(repls, 1):
    n = content.count(old)
    if n != 1:
        missing.append((i, n))
    else:
        content = content.replace(old, new)

if missing:
    print(f"[{path}] ABORTED. bad counts: {missing}")
else:
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print(f"[{path}] OK: {len(repls)} replacements applied")
