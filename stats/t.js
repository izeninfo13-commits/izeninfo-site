/* IZENINFO Statistiques – mesure d'audience sans cookie.
   <script defer src="https://izeninfo.fr/stats/t.js" data-site="CLÉ"></script> */
(function () {
  "use strict";
  var s = document.currentScript; if (!s) return;
  var key = s.getAttribute("data-site"); if (!key) return;
  if (navigator.doNotTrack === "1" || window.doNotTrack === "1") return;
  if (/^(localhost|127\.|192\.168\.)/.test(location.hostname)) return;
  /* Robots pilotés (navigateurs automatisés) : jamais comptés */
  if (navigator.webdriver) return;
  /* Exclure ses propres visites : ouvrir le site une fois avec ?izs=moi (et ?izs=compter pour annuler) */
  try {
    var opt = new URLSearchParams(location.search).get("izs");
    if (opt === "moi") localStorage.setItem("izs_off", "1");
    else if (opt === "compter") localStorage.removeItem("izs_off");
    if (localStorage.getItem("izs_off") === "1") return;
  } catch (e) {}
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
    var q = new URLSearchParams(location.search), m = {};
    /* Paramètres de campagne : publicités Google (gclid, gbraid, wbraid, gad_source), Microsoft, Facebook, UTM */
    [["gclid", "gclid"], ["gbraid", "gbraid"], ["wbraid", "wbraid"], ["gad", "gad_source"], ["msclkid", "msclkid"], ["fbclid", "fbclid"],
     ["us", "utm_source"], ["um", "utm_medium"], ["uc", "utm_campaign"],
     /* Autres marqueurs posés par les plateformes : Google Shopping, TikTok, LinkedIn, X, partages Instagram, e-mailings */
     ["srs", "srsltid"], ["tt", "ttclid"], ["li", "li_fat_id"], ["tw", "twclid"], ["ig", "igshid"], ["mc", "mc_cid"], ["hs", "_hsenc"]].forEach(function (k) {
      var v = q.get(k[1]); if (v) m[k[0]] = /^(us|um|uc)$/.test(k[0]) ? v.slice(0, 80) : "1"; });
    if (!m.ig && q.get("igsh")) m.ig = "1";
    /* Indices pour les arrivées sans provenance : type de navigation, application installée, onglet neuf */
    var nav = ""; try { var ne = performance.getEntriesByType("navigation")[0]; nav = ne ? ne.type : ""; } catch (e) {}
    var app = 0; try { app = window.matchMedia("(display-mode: standalone)").matches || navigator.standalone ? 1 : 0; } catch (e) {}
    send({ t: "pv", p: p, r: ref, i: internal, u: q.get("utm_source") || q.get("ref") || "", q: m, w: window.innerWidth, z: tz,
           n: sent ? "" : nav, a: app });
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
