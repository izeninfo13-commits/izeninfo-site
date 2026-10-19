/* IZENINFO Statistiques – mesure d'audience sans cookie.
   <script defer src="https://izeninfo.fr/stats/t.js" data-site="CLÉ"></script> */
(function () {
  "use strict";
  var s = document.currentScript; if (!s) return;
  var key = s.getAttribute("data-site"); if (!key) return;
  if (navigator.doNotTrack === "1" || window.doNotTrack === "1") return;
  if (/^(localhost|127\.|192\.168\.)/.test(location.hostname)) return;
  var endpoint = (s.getAttribute("data-api") || new URL(s.src).origin) + "/stats/collect.php";
  var tz = ""; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ""; } catch (e) {}

  function send(data) {
    data.k = key;
    var body = JSON.stringify(data);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: "text/plain" }))) return;
    } catch (e) {}
    try { fetch(endpoint, { method: "POST", body: body, keepalive: true, mode: "no-cors", headers: { "Content-Type": "text/plain" } }); } catch (e) {}
  }

  var last = "";
  function pageview() {
    var p = location.pathname; if (p === last) return; last = p;
    var ref = document.referrer, internal = 0;
    try { if (ref && new URL(ref).host === location.host) { ref = ""; internal = 1; } } catch (e) { ref = ""; }
    if (sent) internal = 1;
    var q = new URLSearchParams(location.search);
    send({ t: "pv", p: p, r: ref, i: internal, u: q.get("utm_source") || q.get("ref") || "", w: window.innerWidth, z: tz });
    sent = true;
  }
  var sent = false;
  pageview();

  /* Navigation des sites en une page (history API) */
  var push = history.pushState;
  history.pushState = function () { push.apply(this, arguments); setTimeout(pageview, 0); };
  window.addEventListener("popstate", pageview);

  /* Présence en direct : signal toutes les 30 s tant que l'onglet est visible */
  setInterval(function () { if (document.visibilityState === "visible") send({ t: "hb", p: location.pathname }); }, 30000);

  /* Actions de contact : clics téléphone / e-mail, envois de formulaire */
  document.addEventListener("click", function (e) {
    var a = e.target && e.target.closest ? e.target.closest("a[href]") : null; if (!a) return;
    var h = a.getAttribute("href") || "";
    if (h.indexOf("tel:") === 0) send({ t: "ev", e: "tel", p: location.pathname });
    else if (h.indexOf("mailto:") === 0) send({ t: "ev", e: "mail", p: location.pathname });
  }, true);
  document.addEventListener("submit", function () { send({ t: "ev", e: "form", p: location.pathname }); }, true);
})();
