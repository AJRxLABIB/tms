/**
 * js/ai-feedback.js — shared thumbs up/down feedback widget for AI-generated
 * content (Round 115, master prompt item 14 — "AI Feedback Loop").
 *
 * Deliberately tiny and non-invasive: one function, no state, no polling,
 * no library dependency. Any page that renders AI output can drop in a
 * small feedback control and call tmsSendAiFeedback(feature, contextRef,
 * rating, buttonEl) from it -- the widget replaces its own container's
 * content with a thank-you (or error) message once the request settles, so
 * there's no risk of double-submitting the same feedback by clicking twice.
 *
 * Requires a `csrf_token` hidden field to already be rendered somewhere on
 * the page (tms_csrf_field()) -- reuses it exactly the way my-trip.php's
 * own mtGenerateItinerary() already does, rather than rendering a second
 * one.
 */
function tmsSendAiFeedback(feature, contextRef, rating, buttonEl) {
    var container = buttonEl ? buttonEl.closest('.tms-ai-feedback') : null;
    var csrf = (document.querySelector('[name="csrf_token"]') || {}).value || '';

    if (container) {
        container.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    }

    var body = new URLSearchParams({
        feature: feature,
        context_ref: String(contextRef == null ? '' : contextRef),
        rating: String(rating),
        csrf_token: csrf
    });

    fetch('api/ai.php?action=ai_feedback', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!container) return;
            if (res && res.success) {
                container.innerHTML = '<span style="color:#888;font-size:12px;"><i class="fa fa-check"></i> Thanks for your feedback</span>';
            } else {
                container.innerHTML = '<span style="color:#c0392b;font-size:12px;">' +
                    ((res && res.error) ? res.error : 'Could not save feedback right now.') + '</span>';
            }
        })
        .catch(function () {
            if (container) {
                container.innerHTML = '<span style="color:#c0392b;font-size:12px;">Network error — please try again.</span>';
            }
        });
}
