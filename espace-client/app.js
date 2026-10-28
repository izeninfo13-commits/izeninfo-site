/* IZENINFO – Espace client */
(() => {
  "use strict";
  const $ = id => document.getElementById(id);
  const API = "api.php";
  let state = { csrf: "", user: null, sites: [], site: null, range: "30d", data: null };
  let liveTimer = null, statsTimer = null;
  const nf = new Intl.NumberFormat("fr-FR");
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  async function api(a, body, params = "") {
    const opt = body ? { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ...body, csrf: state.csrf }) } : {};
    const r = await fetch(`${API}?a=${a}${params}`, { credentials: "same-origin", ...opt });
    let j = {};
    try { j = await r.json(); } catch (e) { j = { error: "Réponse invalide du serveur." }; }
    if (r.status === 401 && a !== "login") { boot(); throw new Error(j.error || "Connexion requise."); }
    if (!r.ok) throw new Error(j.error || "Erreur " + r.status);
    return j;
  }

  /* ---------------- Démarrage ---------------- */
  async function boot() {
    clearInterval(liveTimer); clearInterval(statsTimer);
    const s = await api("state");
    state.csrf = s.csrf; state.user = s.user; state.sites = s.sites || [];
    if (!s.user) return showAuth(s.setup);
    $("vAuth").hidden = true; $("vApp").hidden = false;
    $("navTabs").hidden = s.user.role !== "admin";
    fillSites();
    let saved = null; try { saved = localStorage.getItem("izs-site"); } catch (e) {}
    const opts = siteOptions();
    state.site = opts.find(x => String(x.id) === saved) || opts[0] || null;
    if (state.site) $("siteSel").value = state.site.id;
    showView("stats");
  }

  const ALL = { id: "all", name: "Tous les sites", domain: "Vue d'ensemble de tous les comptes", skey: null };
  function siteOptions() { return (state.user && state.user.role === "admin" && state.sites.length > 1 ? [ALL] : []).concat(state.sites); }
  function fillSites() {
    const sel = $("siteSel"), opts = siteOptions();
    sel.innerHTML = opts.map(x => `<option value="${x.id}">${x.id === "all" ? "Tous les sites · vue d'ensemble" : esc(x.name) + " · " + esc(x.domain)}</option>`).join("");
    sel.hidden = opts.length < 2;
    if (state.site) sel.value = state.site.id;
  }

  function showAuth(setup) {
    $("vApp").hidden = true; $("vAuth").hidden = false;
    $("fName").hidden = !setup;
    $("authTitle").textContent = setup ? "Créer le compte administrateur" : "Espace client";
    $("authLead").textContent = setup ? "Première connexion : choisissez l'e-mail et le mot de passe d'IZENINFO. Ce formulaire disparaît ensuite." : "Consultez les statistiques de votre site en temps réel.";
    $("authBtn").textContent = setup ? "Créer le compte" : "Se connecter";
    $("iPass").autocomplete = setup ? "new-password" : "current-password";
    $("authNote").hidden = setup;
    $("fLogin").dataset.mode = setup ? "setup" : "login";
    $("iEmail").focus();
  }

  $("fLogin").addEventListener("submit", async e => {
    e.preventDefault();
    const setup = e.target.dataset.mode === "setup";
    $("authErr").textContent = "";
    $("authBtn").disabled = true;
    try {
      await api(setup ? "setup" : "login", { email: $("iEmail").value, password: $("iPass").value, name: $("iName").value });
      $("iPass").value = "";
      await boot();
    } catch (err) { $("authErr").textContent = err.message; }
    finally { $("authBtn").disabled = false; }
  });

  $("bLogout").addEventListener("click", async () => { await api("logout", {}); boot(); });

  /* Mot de passe */
  $("bPass").addEventListener("click", () => { $("pErr").textContent = ""; $("fPass").reset(); $("dPass").showModal(); });
  $("pCancel").addEventListener("click", () => $("dPass").close());
  $("fPass").addEventListener("submit", async e => {
    e.preventDefault();
    try { await api("password", { old: $("pOld").value, new: $("pNew").value }); $("dPass").close(); }
    catch (err) { $("pErr").textContent = err.message; }
  });

  /* Navigation */
  document.querySelectorAll("#navTabs button").forEach(b => b.addEventListener("click", () => showView(b.dataset.view)));
  function showView(v) {
    document.querySelectorAll("#navTabs button").forEach(b => b.setAttribute("aria-pressed", String(b.dataset.view === v)));
    $("vStats").hidden = v !== "stats"; $("vAdmin").hidden = v !== "admin";
    clearInterval(liveTimer); clearInterval(statsTimer);
    if (v === "stats") startStats(); else loadAdmin();
  }

  $("siteSel").addEventListener("change", e => {
    state.site = siteOptions().find(x => String(x.id) === e.target.value);
    try { localStorage.setItem("izs-site", state.site.id); } catch (err) {}
    startStats();
  });
  try { const r = localStorage.getItem("izs-range"); if (r) state.range = r; } catch (e) {}
  document.querySelectorAll("#ranges button").forEach(b => {
    b.setAttribute("aria-pressed", String(b.dataset.range === state.range));
    b.addEventListener("click", () => {
      state.range = b.dataset.range;
      try { localStorage.setItem("izs-range", state.range); } catch (e) {}
      document.querySelectorAll("#ranges button").forEach(x => x.setAttribute("aria-pressed", String(x === b)));
      loadStats();
    });
  });

  /* ---------------- Statistiques ---------------- */
  function startStats() {
    clearInterval(liveTimer); clearInterval(statsTimer);
    if (!state.site) {
      $("siteTitle").firstChild.textContent = "Aucun site";
      $("siteDomain").textContent = "Votre accès n'est rattaché à aucun site pour le moment.";
      return;
    }
    $("siteTitle").firstChild.textContent = state.site.name;
    $("siteDomain").textContent = state.site.domain;
    state.rev = null; state.lastSync = 0; state.fails = 0;
    loadStats(); pulse();
    liveTimer = setInterval(() => { if (!document.hidden) pulse(); }, 5000);
    statsTimer = setInterval(() => { if (!document.hidden) loadStats(true); }, 60000);
  }

  /* Temps réel : toutes les 5 s, on interroge le serveur ; si une nouvelle donnée est arrivée
     (visite, page vue, contact), tout le tableau de bord est rechargé aussitôt. */
  let pulsing = false, loadingStats = false;
  async function pulse() {
    if (pulsing || !state.site) return;
    pulsing = true;
    try {
      const l = await api("live", null, `&site=${state.site.id}`);
      setText("liveN", nf.format(l.count));
      const many = state.site.id === "all";
      $("liveLbl").textContent = (l.count > 1 ? "visiteurs sur " : "visiteur sur ") + (many ? "l'ensemble des sites" : "le site");
      $("livePages").innerHTML = l.pages.slice(0, 4).map(p => `<li><span>${esc(p.path)}</span><span>${p.n}</span></li>`).join("");
      const lc = $("liveCities"), cs = (l.cities || []).filter(c => c.n);
      lc.hidden = !cs.length;
      setText("liveCities", cs.length ? `<b>Villes en direct</b>` + cs.slice(0, 6).map(c => `<span class="chip">${esc(c.name)} <i>${c.n}</i></span>`).join("") : "", true);
      if (state.rev !== null && l.rev !== state.rev) loadStats(true);
      state.rev = l.rev; state.lastSync = Date.now(); state.fails = 0;
    } catch (e) { state.fails++; }
    finally { pulsing = false; tick(); }
  }

  async function loadStats(quiet) {
    if (loadingStats) { state.pending = true; return; }
    loadingStats = true; state.pending = false;
    try {
      const d = await api("stats", null, `&site=${state.site.id}&range=${state.range}`);
      state.data = d; render(d);
      state.lastSync = Date.now();
      $("updated").textContent = "Dernière mise à jour à " + new Date().toLocaleTimeString("fr-FR");
    } catch (e) { if (!quiet) $("updated").textContent = e.message; state.fails++; }
    finally { loadingStats = false; tick(); if (state.pending) loadStats(true); }
  }

  /* Indicateur « Temps réel » */
  function tick() {
    const el = $("rt"); if (!el) return;
    let txt, cls;
    if (document.hidden) { txt = "Temps réel en pause"; cls = "pause"; }
    else if (state.fails >= 3) { txt = "Connexion perdue, nouvel essai…"; cls = "off"; }
    else if (!state.lastSync) { txt = "Connexion…"; cls = "pause"; }
    else { const sec = Math.max(0, Math.round((Date.now() - state.lastSync) / 1000)); txt = "Temps réel · actualisé " + (sec < 2 ? "à l'instant" : `il y a ${sec} s`); cls = "on"; }
    el.querySelector("span").textContent = txt;
    el.className = "rt " + cls;
  }
  setInterval(tick, 1000);
  document.addEventListener("visibilitychange", () => { tick(); if (!document.hidden && state.site && !$("vStats").hidden) { pulse(); loadStats(true); } });

  /* Met à jour un chiffre et le fait briller s'il a changé */
  function setText(id, value, html) {
    const el = $(id); if (!el) return;
    const before = html ? el.innerHTML : el.textContent;
    if (before === value) return;
    if (html) el.innerHTML = value; else el.textContent = value;
    if (before && before !== "–" && !reduceMotion) { el.classList.remove("flash"); void el.offsetWidth; el.classList.add("flash"); }
  }
  const reduceMotion = window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches;

  function delta(cur, prev) {
    if (!prev && !cur) return "";
    if (!prev) return `<span class="up" aria-hidden="true">▲</span> nouveau`;
    const p = Math.round((cur - prev) / prev * 100);
    if (p === 0) return `= stable vs période précédente`;
    const up = p > 0;
    return `<span class="${up ? "up" : "down"}" aria-hidden="true">${up ? "▲" : "▼"}</span> ${up ? "+" : ""}${p} % vs période précédente`;
  }

  function render(d) {
    const k = d.kpi, p = d.prev;
    setText("kV", nf.format(k.visitors)); $("dV").innerHTML = delta(k.visitors, p.visitors);
    setText("kP", nf.format(k.pageviews)); $("dP").innerHTML = delta(k.pageviews, p.pageviews);
    setText("kR", String(k.ppv).replace(".", ",")); $("dR").innerHTML = delta(k.ppv, p.ppv);
    setText("kC", nf.format(k.contacts)); $("dC").innerHTML = delta(k.contacts, p.contacts);
    const empty = !k.pageviews && !p.pageviews;
    $("install").hidden = !empty || d.all;
    renderCompare(d);
    if (empty) $("installCode").textContent = `<script defer src="https://izeninfo.fr/stats/t.js" data-site="${state.site.skey}"></script>`;
    const labels = { today: "Aujourd'hui, par heure", "7d": "7 derniers jours", "30d": "30 derniers jours", "12m": "12 derniers mois, par mois" };
    $("trafficSub").textContent = labels[d.range] || "";
    if ($("chart").dataset.hover === "1") state.chartPending = true; else chart(d);
    table(d);
    const visits = d.devices.reduce((a, r) => a + r.n, 0);
    bars("lSources", d.sources, false, "Aucune visite sur la période");
    renderChannels(d);
    renderDirect(d);
    const cityVisits = d.cities.reduce((a, r) => a + r.n, 0) + (d.unknown_city || 0);
    bars("lCities", d.cities, cityVisits, "Aucune ville identifiée pour le moment");
    $("citiesSub").textContent = "Ville approximative des visiteurs (visites)" + (d.unknown_city ? ` · ${nf.format(d.unknown_city)} visite${d.unknown_city > 1 ? "s" : ""} hors zone ou non identifiée${d.unknown_city > 1 ? "s" : ""}` : "");
    renderAds(d);
    bars("lPages", d.pages, false, "Aucune page vue sur la période");
    bars("lDevices", d.devices, visits, "Aucune donnée");
    bars("lCountries", d.countries, visits, "Aucune donnée");
    bars("lBrowsers", d.browsers, visits, "Aucune donnée");
    setText("eTel", nf.format(d.events.tel)); setText("eMail", nf.format(d.events.mail)); setText("eForm", nf.format(d.events.form));
  }

  const CH_COLORS = { "Google Ads (payant)": "#3987e5", "Google (naturel)": "#5FC58A", "Fiche Google (Maps)": "#E8B44A", "Accès direct": "#8D9AB3",
    "Réseaux sociaux": "#C08CF0", "Autres moteurs": "#4FC3C9", "Sites référents": "#d95926", "E-mail": "#F08CB4", "Autres publicités": "#7FA8F0", "Campagnes (UTM)": "#B7C7A0", "Non attribué": "#5A6680",
    "Messageries": "#6FD3A8", "QR code / imprimés": "#F2D06B" };
  const pctFmt = v => String(v).replace(".", ",") + " %";
  function renderChannels(d) {
    const rows = d.channels || [];
    const total = rows.reduce((a, r) => a + r.visits, 0);
    const rates = rows.filter(r => r.visits >= 5).map(r => r.converted / r.visits);
    const best = rates.length ? Math.max(...rates) : -1;
    $("chanBody").innerHTML = rows.length ? rows.map(r => {
      const rate = r.visits ? r.converted / r.visits : 0;
      return `<tr class="${r.name === "Google Ads (payant)" ? "paid" : ""}"><td class="txt"><span class="dot" style="background:${CH_COLORS[r.name] || "#8D9AB3"}"></span>${esc(r.name)}</td>
        <td>${nf.format(r.visits)}</td><td>${total ? Math.round(r.visits / total * 100) + " %" : "–"}</td><td>${nf.format(r.contacts)}</td>
        <td class="rate ${r.visits >= 5 && rate === best && best > 0 ? "best" : ""}">${r.visits ? pctFmt(Math.round(rate * 1000) / 10) : "–"}</td></tr>`;
    }).join("") : `<tr><td class="txt" colspan="5">Aucune visite sur la période</td></tr>`;
  }

  function renderDirect(d) {
    const D = d.direct, box = $("directBox");
    linkSite = d.all ? null : d.site; buildLink();
    box.hidden = !D;
    if (!D) return;
    $("directSub").textContent = `${nf.format(D.total)} visite${D.total > 1 ? "s" : ""} sans provenance transmise par le navigateur sur la période. Pour chacune, l'indice le plus probable :`;
    bars("lDirectKinds", D.kinds, D.total, "Aucune donnée");
    bars("lDirectPages", D.pages, D.total, "Aucune donnée");
    bars("lDirectDevices", D.devices, D.total, "Aucune donnée");
  }

  /* Générateur de lien suivi */
  const LK = { qr: ["qr", "qrcode"], whatsapp: ["whatsapp", "messagerie"], email: ["email", "email"], gbp: ["google", "gbp"], social: ["instagram", "social"] };
  let linkSite = null;
  function buildLink() {
    const dom = linkSite && linkSite.domain ? linkSite.domain : location.host;
    const [src, med] = LK[$("lkMedium").value] || LK.qr;
    const camp = ($("lkCamp").value || "").trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    $("lkOut").value = `https://${dom}/?utm_source=${src}&utm_medium=${med}${camp ? "&utm_campaign=" + camp : ""}`;
  }
  ["lkMedium", "lkCamp"].forEach(id => $(id).addEventListener("input", buildLink));
  $("lkCopy").addEventListener("click", async () => {
    buildLink();
    try { await navigator.clipboard.writeText($("lkOut").value); $("lkCopy").textContent = "Copié"; } catch (e) { $("lkOut").select(); }
    setTimeout(() => { $("lkCopy").textContent = "Copier"; }, 1500);
  });

  const euro = v => v == null ? "–" : new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: v < 10 ? 2 : 0 }).format(v);
  function renderAds(d) {
    const box = $("adsCard"), A = d.ads;
    box.hidden = !A;
    if (!A) return;
    if (document.activeElement !== $("iBudget")) $("iBudget").value = A.budget || "";
    const k = (label, value, small) => `<div><span>${label}</span><b>${value}</b>${small ? `<small>${small}</small>` : ""}</div>`;
    $("adsKpis").innerHTML = [
      k("Visites Google Ads", nf.format(A.visits), `${pctFmt(A.share)} du trafic`),
      k("Contacts obtenus", nf.format(A.contacts), `${nf.format(A.converted)} visiteur${A.converted > 1 ? "s ont" : " a"} contacté`),
      k("Taux de contact", A.visits ? pctFmt(A.rate) : "–", `autres canaux : ${pctFmt(A.other_rate)}`),
      k("Pages par visite", String(A.ppv).replace(".", ","), `autres canaux : ${String(A.other_ppv).replace(".", ",")}`),
      k("Coût par visite", A.cpv != null ? euro(A.cpv) : "–", A.budget ? `budget estimé ${euro(A.spent)} sur ${A.days} j` : "renseignez le budget"),
      k("Coût par contact", A.cpc != null ? euro(A.cpc) : "–", A.budget ? (A.converted ? "par visiteur ayant contacté" : "aucun contact sur la période") : "renseignez le budget"),
    ].join("");
    bars("lAdsCamp", A.campaigns, false, "Aucune campagne sur la période");
    bars("lAdsCities", A.cities, A.visits, "Aucune ville identifiée");
    bars("lAdsPages", A.landing, false, "Aucune page d'arrivée");
    const v = $("adsVerdict");
    let txt, cls;
    if (!A.visits) {
      txt = "Aucune visite issue de Google Ads sur cette période. Si des annonces sont actives, vérifiez que le marquage automatique (paramètre gclid) est activé dans le compte Google Ads : sans lui, les clics payants ne peuvent pas être distingués de Google naturel.";
      cls = "warn";
    } else if (A.converted === 0) {
      txt = `Les annonces ont amené ${nf.format(A.visits)} visite${A.visits > 1 ? "s" : ""}, mais aucune n'a abouti à un appel, un e-mail ou un formulaire${A.budget ? ` pour un budget estimé de ${euro(A.spent)}` : ""}. À surveiller : ciblage des mots-clés et des villes, page d'arrivée.`;
      cls = "bad";
    } else if (A.rate >= A.other_rate) {
      txt = `Les annonces sont efficaces : ${pctFmt(A.rate)} des visiteurs Google Ads vous contactent, contre ${pctFmt(A.other_rate)} pour les autres canaux${A.cpc != null ? `, soit environ ${euro(A.cpc)} par contact` : ""}.`;
      cls = "good";
    } else {
      txt = `Les annonces génèrent des contacts (${nf.format(A.contacts)}), mais convertissent moins que vos autres canaux (${pctFmt(A.rate)} contre ${pctFmt(A.other_rate)})${A.cpc != null ? ` ; coût estimé ${euro(A.cpc)} par contact` : ""}.`;
      cls = "warn";
    }
    v.textContent = txt; v.className = "verdict " + cls;
    $("adsNote").textContent = "Les visites Google Ads sont reconnues grâce au marquage automatique de Google (gclid, gbraid, wbraid, gad_source) ou aux paramètres UTM « cpc ». Un contact = clic sur le téléphone, sur l'e-mail ou formulaire envoyé pendant la visite. Le coût est estimé à partir du budget mensuel renseigné, réparti sur la période. Les villes sont approximatives (localisation de l'opérateur internet).";
  }
  $("fBudget").addEventListener("submit", async e => {
    e.preventDefault();
    if (!state.site || state.site.id === "all") return;
    const btn = e.target.querySelector("button");
    try { await api("set_budget", { site_id: state.site.id, budget: $("iBudget").value }); btn.textContent = "Enregistré"; setTimeout(() => btn.textContent = "Enregistrer", 1500); loadStats(true); }
    catch (err) { btn.textContent = "Erreur"; }
  });

  function renderCompare(d) {
    const box = $("compare");
    box.hidden = !d.all;
    if (!d.all) return;
    const t = d.totals;
    $("compareSub").textContent = `${t.sites} sites suivis · ${t.clients} compte${t.clients > 1 ? "s" : ""} client${t.clients > 1 ? "s" : ""} · ${t.active} site${t.active > 1 ? "s" : ""} actif${t.active > 1 ? "s" : ""} sur 7 jours`;
    const pct = (c, p) => !p ? (c ? '<span class="up" aria-hidden="true">▲</span> nouveau' : "–") : (() => { const v = Math.round((c - p) / p * 100); return v === 0 ? "= stable" : `<span class="${v > 0 ? "up" : "down"}" aria-hidden="true">${v > 0 ? "▲" : "▼"}</span> ${v > 0 ? "+" : ""}${v} %`; })();
    const last = ts => ts ? new Date(ts * 1000).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" }) : "aucune visite reçue";
    $("compareBody").innerHTML = d.per_site.map(r => `<tr data-site="${r.id}" tabindex="0" title="Voir le détail de ${esc(r.name)}">
      <td><strong>${esc(r.name)}</strong><small>${esc(r.domain)}</small></td>
      <td class="txt">${r.owner ? esc(r.owner) : '<span class="muted">non attribué</span>'}</td>
      <td>${r.live ? `<span class="pill-live">${r.live}</span>` : "0"}</td>
      <td>${nf.format(r.visitors)}</td><td class="delta-cell">${pct(r.visitors, r.prev_visitors)}</td>
      <td>${nf.format(r.pageviews)}</td><td>${nf.format(r.contacts)}</td><td class="txt">${last(r.last_hit)}</td></tr>`).join("");
  }
  $("compareBody").addEventListener("click", e => openSite(e.target.closest("tr")));
  $("compareBody").addEventListener("keydown", e => { if (e.key === "Enter") openSite(e.target.closest("tr")); });
  function openSite(tr) {
    if (!tr) return;
    const s = state.sites.find(x => String(x.id) === tr.dataset.site); if (!s) return;
    state.site = s; $("siteSel").value = s.id;
    try { localStorage.setItem("izs-site", s.id); } catch (err) {}
    startStats(); window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function bars(id, rows, total, emptyMsg) {
    const el = $(id);
    if (!rows.length) { el.innerHTML = `<li class="empty">${emptyMsg}</li>`; return; }
    const max = Math.max(...rows.map(r => r.n), 1);
    el.innerHTML = rows.map(r => {
      const pct = total ? Math.round(r.n / total * 100) : null;
      return `<li title="${esc(r.name)} : ${nf.format(r.n)}"><span class="name">${esc(r.name)}</span><span class="n">${nf.format(r.n)}${pct !== null ? `<small>${pct} %</small>` : ""}</span><span class="track" aria-hidden="true"><i style="width:${Math.max(2, r.n / max * 100)}%"></i></span></li>`;
    }).join("");
  }

  function fmtLabel(s, bucket, long) {
    if (bucket === "hour") return s;
    if (bucket === "month") { const [y, m] = s.split("-"); return new Date(+y, +m - 1, 1).toLocaleDateString("fr-FR", long ? { month: "long", year: "numeric" } : { month: "short" }); }
    const [y, m, dd] = s.split("-"); return new Date(+y, +m - 1, +dd).toLocaleDateString("fr-FR", long ? { weekday: "long", day: "numeric", month: "long" } : { day: "numeric", month: "short" });
  }

  function niceMax(v) { if (v <= 4) return 4; const p = Math.pow(10, Math.floor(Math.log10(v))); const n = v / p; const step = [1, 1.2, 1.6, 2, 2.4, 3, 4, 5, 6, 8, 10].find(x => n <= x); return step * p; }

  function chart(d) {
    const box = $("chart"), S = d.series;
    const W = Math.max(300, box.clientWidth), H = 280, L = 40, R = 12, T = 12, B = 30;
    const iw = W - L - R, ih = H - T - B;
    const max = niceMax(Math.max(1, ...S.map(r => Math.max(r.visitors, r.pageviews))));
    const x = i => L + (S.length < 2 ? iw / 2 : i * iw / (S.length - 1));
    const y = v => T + ih - v / max * ih;
    const line = key => S.map((r, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(r[key]).toFixed(1)}`).join("");
    let g = "";
    for (let t = 0; t <= 4; t++) { const v = max * t / 4, yy = y(v); g += `<line class="grid-l" x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}"/><text class="axis-t" x="${L - 8}" y="${yy + 4}" text-anchor="end">${nf.format(v)}</text>`; }
    const step = Math.ceil(S.length / Math.max(2, Math.floor(iw / 80)));
    S.forEach((r, i) => { if (i % step === 0 || i === S.length - 1 && S.length < 14) g += `<text class="axis-t" x="${x(i)}" y="${H - 8}" text-anchor="middle">${esc(fmtLabel(r.label, d.bucket))}</text>`; });
    const area = `${line("visitors")}L${x(S.length - 1)},${T + ih}L${x(0)},${T + ih}Z`;
    box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Visiteurs et pages vues sur la période">
      <defs><linearGradient id="gA1" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3987e5" stop-opacity=".28"/><stop offset="1" stop-color="#3987e5" stop-opacity="0"/></linearGradient></defs>
      ${g}<path class="a1" d="${area}"/><path class="s2" d="${line("pageviews")}"/><path class="s1" d="${line("visitors")}"/>
      <g id="hov" visibility="hidden"><line class="xh" id="hx" y1="${T}" y2="${T + ih}"/><circle class="m2" id="hm2" r="4.5"/><circle class="m1" id="hm1" r="4.5"/></g>
      <rect x="${L}" y="${T}" width="${iw}" height="${ih}" fill="transparent" id="hit"/></svg><div class="tip" id="tip" hidden></div>`;
    const hit = $("hit"), tip = $("tip"), hov = $("hov");
    const show = ev => {
      const rect = box.getBoundingClientRect();
      const px = (ev.clientX - rect.left) * (W / rect.width);
      const i = Math.max(0, Math.min(S.length - 1, Math.round((px - L) / (iw / Math.max(1, S.length - 1)))));
      const r = S[i], xx = x(i);
      hov.setAttribute("visibility", "visible");
      $("hx").setAttribute("x1", xx); $("hx").setAttribute("x2", xx);
      $("hm1").setAttribute("cx", xx); $("hm1").setAttribute("cy", y(r.visitors));
      $("hm2").setAttribute("cx", xx); $("hm2").setAttribute("cy", y(r.pageviews));
      tip.hidden = false;
      tip.innerHTML = `<b>${esc(fmtLabel(r.label, d.bucket, true))}</b><div><span><i style="background:var(--series-1)"></i>Visiteurs</span><strong>${nf.format(r.visitors)}</strong></div><div><span><i style="background:var(--series-2)"></i>Pages vues</span><strong>${nf.format(r.pageviews)}</strong></div>`;
      const left = xx / W * rect.width, top = Math.min(y(r.visitors), y(r.pageviews)) / H * rect.height;
      tip.style.left = Math.max(90, Math.min(rect.width - 90, left)) + "px";
      tip.style.top = Math.max(60, top - 10) + "px";
    };
    hit.addEventListener("pointermove", show);
    hit.addEventListener("pointerdown", show);
    hit.addEventListener("pointerenter", () => { box.dataset.hover = "1"; });
    hit.addEventListener("pointerleave", () => {
      hov.setAttribute("visibility", "hidden"); tip.hidden = true; box.dataset.hover = "";
      if (state.chartPending) { state.chartPending = false; chart(state.data); }
    });
  }

  function table(d) {
    $("tableWrap").innerHTML = `<table class="data"><thead><tr><th>${d.bucket === "hour" ? "Heure" : d.bucket === "month" ? "Mois" : "Jour"}</th><th>Visiteurs</th><th>Pages vues</th></tr></thead><tbody>${d.series.slice().reverse().map(r => `<tr><td>${esc(fmtLabel(r.label, d.bucket, true))}</td><td>${nf.format(r.visitors)}</td><td>${nf.format(r.pageviews)}</td></tr>`).join("")}</tbody></table>`;
  }
  $("bTable").addEventListener("click", e => {
    const open = $("tableWrap").hidden;
    $("tableWrap").hidden = !open; e.target.setAttribute("aria-expanded", String(open));
    e.target.textContent = open ? "Masquer le tableau" : "Afficher le tableau";
  });
  let rz; window.addEventListener("resize", () => { clearTimeout(rz); rz = setTimeout(() => state.data && !$("vStats").hidden && chart(state.data), 150); });

  /* ---------------- Administration ---------------- */
  async function loadAdmin() {
    const box = $("clients");
    try {
      const d = await api("clients");
      const lastHit = s => s.last_hit ? "dernière visite " + new Date(s.last_hit * 1000).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" }) : "aucune visite reçue";
      const free = d.sites.filter(s => !s.user_id);
      const freeHtml = free.length ? `<article class="client"><header><h3>Sites référencés sans accès client</h3><span class="meta">${free.length} site${free.length > 1 ? "s" : ""} · statistiques visibles par vous uniquement</span></header>
        <ul class="sites">${free.map(s => `<li><strong>${esc(s.name)}</strong> <span class="muted">· ${esc(s.domain)}</span> <span class="meta">· ${lastHit(s)}</span><span class="code">${esc(s.snippet)}</span>
          <form class="assign form-grid" data-site="${s.id}" style="margin-top:10px"><div class="field"><label>Nom du client</label><input name="name" value="${esc(s.name)}"></div><div class="field"><label>E-mail du client</label><input name="email" type="email" required placeholder="contact@${esc(s.domain)}"></div>
          <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap"><button class="btn sm primary" type="submit">Donner l'accès au client</button><button class="btn sm" type="button" data-copy="${esc(s.snippet)}">Copier le code</button></div><div class="out" style="grid-column:1/-1"></div></form></li>`).join("")}</ul></article>` : "";
      box.innerHTML = freeHtml + d.users.map(u => {
        const sites = d.sites.filter(s => s.user_id === u.id);
        const last = u.last_login ? new Date(u.last_login * 1000).toLocaleDateString("fr-FR") : "jamais";
        return `<article class="client" data-uid="${u.id}">
          <header><h3>${esc(u.name)}${u.role === "admin" ? ' <span class="muted" style="font-size:13px;font-weight:500">· administrateur</span>' : ""}</h3><span class="meta">${esc(u.email)} · dernière connexion : ${last}</span></header>
          <ul class="sites">${sites.map(s => `<li><strong>${esc(s.name)}</strong> <span class="muted">· ${esc(s.domain)}</span> <span class="meta">· ${s.last_hit ? "dernière visite " + new Date(s.last_hit * 1000).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short" }) : "aucune visite reçue"}</span><span class="code">${esc(s.snippet)}</span><button class="btn sm" type="button" data-copy="${esc(s.snippet)}">Copier le code</button></li>`).join("") || '<li class="meta">Aucun site.</li>'}</ul>
          ${u.role === "client" ? `<div class="row"><button class="btn sm" type="button" data-reset="${u.id}">Nouveau mot de passe</button><button class="btn sm danger" type="button" data-del="${u.id}">Supprimer l'accès</button></div><div class="out"></div>` : ""}
        </article>`;
      }).join("");
    } catch (e) { box.innerHTML = `<p class="err">${esc(e.message)}</p>`; }
  }

  $("clients").addEventListener("click", async e => {
    const b = e.target.closest("button"); if (!b || b.type === "submit") return;
    if (b.dataset.done) { loadAdmin(); return; }
    const card = b.closest(".client"), out = card && card.querySelector(".out");
    if (b.dataset.copy) { try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = "Copié"; setTimeout(() => b.textContent = "Copier le code", 1500); } catch (err) {} return; }
    if (b.dataset.reset) {
      try { const r = await api("reset_password", { user_id: +b.dataset.reset }); out.innerHTML = `<div class="secret"><p><strong>Nouveau mot de passe :</strong></p><span class="code">${esc(r.password)}</span><p class="meta" style="margin-top:8px">Il ne sera plus affiché : transmettez-le maintenant au client.</p></div>`; }
      catch (err) { out.innerHTML = `<p class="err">${esc(err.message)}</p>`; }
      return;
    }
    if (b.dataset.del) {
      if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer (les statistiques sont conservées)"; return; }
      try { await api("delete_client", { user_id: +b.dataset.del }); loadAdmin(); state.sites = (await api("state")).sites; }
      catch (err) { out.innerHTML = `<p class="err">${esc(err.message)}</p>`; }
    }
  });

  $("clients").addEventListener("submit", async e => {
    const f = e.target.closest("form.assign"); if (!f) return;
    e.preventDefault();
    const out = f.querySelector(".out"); out.innerHTML = "";
    try {
      const r = await api("assign_client", { site_id: +f.dataset.site, name: f.elements.name.value, email: f.elements.email.value });
      out.innerHTML = r.password
        ? `<div class="secret"><p><strong>Accès créé.</strong> À transmettre au client :</p><p>Adresse : <strong>https://izeninfo.fr/espace-client/</strong><br>Identifiant : <strong>${esc(r.email)}</strong></p><p>Mot de passe (affiché une seule fois) :</p><span class="code">${esc(r.password)}</span><button class="btn sm" type="button" style="margin-top:10px" data-done="1">J'ai transmis le mot de passe</button></div>`
        : `<div class="secret"><p>Site ajouté au compte existant <strong>${esc(r.email)}</strong>.</p><button class="btn sm" type="button" data-done="1">OK</button></div>`;
      f.querySelectorAll("input,button[type=submit]").forEach(x => x.disabled = true);
    } catch (err) { out.innerHTML = `<p class="err">${esc(err.message)}</p>`; }
  });

  $("fClient").addEventListener("submit", async e => {
    e.preventDefault();
    $("cErr").textContent = "";
    try {
      const r = await api("create_client", { name: $("cName").value, email: $("cEmail").value, site_name: $("cSite").value, domain: $("cDomain").value });
      $("cResult").hidden = false;
      $("cResult").innerHTML = `<div class="secret"><p><strong>Accès créé.</strong> À transmettre au client :</p><p>Adresse : <strong>https://izeninfo.fr/espace-client/</strong><br>Identifiant : <strong>${esc(r.email)}</strong></p><p>Mot de passe (affiché une seule fois) :</p><span class="code">${esc(r.password)}</span><p style="margin-top:12px">Code de mesure à placer avant &lt;/head&gt; sur toutes les pages du site :</p><span class="code">${esc(r.snippet)}</span></div>`;
      e.target.reset();
      const s = await api("state"); state.sites = s.sites;
      fillSites();
      loadAdmin();
    } catch (err) { $("cErr").textContent = err.message; }
  });

  boot().catch(e => { document.body.insertAdjacentHTML("afterbegin", `<p class="err" style="padding:20px">${esc(e.message)}</p>`); });
})();
