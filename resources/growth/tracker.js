/*! 10X Engage website tracker v1.0.0 — measures taps on WhatsApp buttons. No cookies, no visitor IDs. */
/* Source of resources/growth/tracker.min.js (served at /sdk/v1/e.min.js). After a change:
   npx terser@5.31.0 resources/growth/tracker.js -c -m --comments '/^!/' -o resources/growth/tracker.min.js */
(function (w, d) {
    'use strict';
    if (w.__xg) return;
    var X = (w.__xg = { v: '1.0.0' });
    var script = d.currentScript || (function () { var s = d.getElementsByTagName('script'); for (var i = s.length - 1; i >= 0; i--) if (/\/sdk\/v1\/e\.min\.js/.test(s[i].src)) return s[i]; })();
    if (!script || !script.src) return;
    var src = new URL(script.src);
    var KEY = src.searchParams.get('key');
    var BASE = src.origin;
    if (!KEY) return;
    var ALPHA = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    var PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid'];
    var STORE = 'xg_landing';
    var cfg = null;

    function ss(get, val) {
        try {
            if (get) return JSON.parse(w.sessionStorage.getItem(STORE) || 'null');
            if (val === null) w.sessionStorage.removeItem(STORE);
            else w.sessionStorage.setItem(STORE, JSON.stringify(val));
        } catch (e) { return null; }
    }
    function params(search) {
        var q = new URLSearchParams(search || ''), out = {}, any = false;
        for (var i = 0; i < PARAMS.length; i++) { var v = q.get(PARAMS[i]); if (v) { out[PARAMS[i]] = v.slice(0, PARAMS[i].length > 6 ? 200 : 512); any = true; } }
        return any ? out : null;
    }
    function refHost() {
        try { var h = d.referrer ? new URL(d.referrer).hostname : ''; return h && h !== location.hostname ? h : null; } catch (e) { return null; }
    }

    // Multi-page campaign memory (on by default; the site's setting can switch it off): the landing
    // page's campaign details, for this browser tab only, cleared with it.
    var here = params(location.search);
    if (here || (!ss(true) && refHost())) ss(false, { c: here || {}, r: refHost(), p: location.pathname });

    // "Open my site" check from Tracking Setup.
    var challenge = new URLSearchParams(location.search).get('xg_verify');
    if (challenge) {
        try { fetch(BASE + '/v1/v', { method: 'POST', body: JSON.stringify({ site_key: KEY, challenge: challenge }), headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, keepalive: true, mode: 'cors', credentials: 'omit' }); } catch (e) {}
    }

    try {
        fetch(BASE + '/v1/config?site_key=' + encodeURIComponent(KEY), { mode: 'cors', credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (c) {
                if (!c || !c.enabled) { cfg = null; return; }
                cfg = c;
                if (!c.session_landing_params_enabled || (c.mode === 'consent' && !X.consent)) ss(false, null);
            })['catch'](function () {});
    } catch (e) {}

    function token() {
        var out = '', b = new Uint8Array(1), max = Math.floor(256 / ALPHA.length) * ALPHA.length;
        while (out.length < 10) { w.crypto.getRandomValues(b); if (b[0] < max) out += ALPHA[b[0] % ALPHA.length]; }
        return 'XG-' + out;
    }
    function uuid() {
        if (w.crypto.randomUUID) return w.crypto.randomUUID();
        var b = new Uint8Array(16); w.crypto.getRandomValues(b); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
        var h = []; for (var i = 0; i < 16; i++) h.push((b[i] + 256).toString(16).slice(1));
        return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' + h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
    }

    // wa.me/<number>, api.whatsapp.com/send?phone=…, whatsapp://send?phone=…
    function parse(href) {
        var u; try { u = new URL(href, location.href); } catch (e) { return null; }
        var phone = null, kind = null;
        if (u.protocol === 'whatsapp:') { kind = 'whatsapp'; phone = u.searchParams.get('phone'); }
        else if (/^(www\.)?wa\.me$/i.test(u.hostname)) { kind = 'wa.me'; phone = u.pathname.replace(/\//g, '') || u.searchParams.get('phone'); }
        else if (/^(api|web)\.whatsapp\.com$/i.test(u.hostname) && /^\/send/.test(u.pathname)) { kind = 'api.whatsapp.com'; phone = u.searchParams.get('phone'); }
        else return null;
        return { url: u, kind: kind, phone: (phone || '').replace(/\D/g, '') };
    }
    function destination(link, el) {
        var want = el && el.getAttribute && el.getAttribute('data-xg-mapping-id');
        var list = cfg.destinations || [];
        for (var i = 0; i < list.length; i++) {
            if (want ? list[i].mapping_id === want : list[i].public_number === link.phone) return list[i];
        }
        return null;
    }
    function lang() {
        var l = (d.documentElement.lang || navigator.language || 'en').toLowerCase();
        return l.indexOf('ar') === 0 ? 'ar' : 'en';
    }

    // Rewrites one WhatsApp link with a fresh reference and sends the tap in the background.
    // Returns the new link, or null to leave the original untouched (fail open).
    function track(href, el) {
        if (!cfg || !w.crypto || !w.crypto.getRandomValues) return null;
        // Consent mode (optional, per site): nothing is measured until the site's cookie banner says yes.
        if (cfg.mode === 'consent' && !X.consent) return null;
        var link = parse(href);
        if (!link) return null;
        var dest = destination(link, el);
        if (!dest) return null;
        var ref = token(), language = lang();
        var tpl = (cfg.templates && cfg.templates[language]) || "Hi, I'd like to know more about your services. (Ref: {reference})";
        var existing = link.url.searchParams.get('text');
        var text = existing ? existing.replace(/\s+$/, '') + (language === 'ar' ? ' (المرجع: ' : ' (Ref: ') + ref + ')' : tpl.replace('{reference}', ref);
        link.url.searchParams.delete('text');
        if (link.kind !== 'wa.me' && !link.url.searchParams.get('phone')) link.url.searchParams.set('phone', dest.public_number);
        // encodeURIComponent (spaces as %20, UTF-8 kept): WhatsApp shows a "+" from form encoding literally on some phones.
        var q = link.url.search ? link.url.search + '&' : '?';
        var out = link.url.origin !== 'null' ? link.url.origin + link.url.pathname : link.url.protocol + '//' + link.url.host + link.url.pathname;
        out += q + 'text=' + encodeURIComponent(text) + link.url.hash;

        var landing = cfg.session_landing_params_enabled ? ss(true) : null;
        var current = params(location.search);
        var event = {
            site_key: KEY, config_version: cfg.config_version, event_id: uuid(), reference: ref, mapping_id: dest.mapping_id,
            page_path: location.pathname, landing_path: landing ? landing.p : null, language: language,
            campaign: current || (landing && landing.c) || {},
            collection_mode: cfg.mode === 'consent' ? 'consent' : 'click_only',
            source_of_campaign_params: current ? 'current_page' : landing && landing.c && Object.keys(landing.c).length ? 'session_landing' : 'current_page',
            referrer_host: (landing && landing.r) || refHost(), occurred_at: new Date().toISOString(), sdk_version: X.v
        };
        var body = JSON.stringify(event), url = cfg.endpoint || BASE + '/v1/c';
        // Queued in the background (sendBeacon / keepalive fetch): nothing waits for the network, and
        // the request survives the page leaving for WhatsApp.
        try {
            if (!(navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'text/plain;charset=UTF-8' })))) {
                fetch(url, { method: 'POST', body: body, headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, keepalive: true, mode: 'cors', credentials: 'omit' })['catch'](function () {});
            }
        } catch (e) {}
        return out;
    }

    // One delegated listener, in the capture phase, survives route changes and new buttons. The
    // link is rewritten synchronously and the browser's own navigation goes ahead (target, rel and
    // modifier keys untouched). A fresh reference on every tap; the original link is not restored.
    function onTap(e) {
        if (e.defaultPrevented && e.type === 'click') return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        try {
            var orig = a.getAttribute('data-xg-href') || a.getAttribute('href');
            if (!parse(orig)) return;
            var next = track(orig, a);
            if (next) { if (!a.getAttribute('data-xg-href')) a.setAttribute('data-xg-href', orig); a.setAttribute('href', next); }
        } catch (err) {}
    }
    d.addEventListener('click', onTap, true);
    d.addEventListener('auxclick', onTap, true);

    // For chat widgets that open WhatsApp from code: XG.link(url) returns the tracked link (or the
    // original) — call it synchronously in the click handler, then open the result.
    // Consent mode: the site's cookie banner calls XG.consent(true) when the visitor accepts, and
    // XG.consent(false) to withdraw (the tab's campaign memory is cleared).
    w.XG = {
        link: function (href) { try { return track(href, null) || href; } catch (e) { return href; } },
        consent: function (granted) {
            X.consent = !!granted;
            if (!granted) ss(false, null);
            else if (here && (!cfg || cfg.session_landing_params_enabled)) ss(false, { c: here, r: refHost(), p: location.pathname });
        },
        version: X.v
    };
})(window, document);
