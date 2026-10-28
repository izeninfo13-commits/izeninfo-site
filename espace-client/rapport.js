/* IZENINFO – Rapport d'audience imprimable (A4), généré à partir des statistiques du tableau de bord. */
(() => {
  "use strict";
  const $ = id => document.getElementById(id);
  const nf = new Intl.NumberFormat("fr-FR");
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const pc = (v, d = 0) => v == null ? "–" : (Math.round(v * Math.pow(10, d)) / Math.pow(10, d)).toLocaleString("fr-FR") + " %";
  const I = window.IZInsights;
  const q = new URLSearchParams(location.search);
  const site = q.get("site") || "", range = q.get("range") || "30d";
  const RANGE = { today: "Aujourd'hui", "7d": "7 derniers jours", "30d": "30 derniers jours", "90d": "90 derniers jours", "12m": "12 derniers mois" };
  const CH = { "Google Ads (payant)": "#3987e5", "Google (naturel)": "#3DBA78", "Fiche Google (Maps)": "#E0A93A", "Accès direct": "#8D9AB3", "Réseaux sociaux": "#B07EE8",
    "Autres moteurs": "#3DB5BB", "Sites référents": "#d95926", "E-mail": "#E879A8", "Autres publicités": "#6F98E8", "Campagnes (UTM)": "#98AA80", "Non attribué": "#5A6680",
    "Messageries": "#4FC594", "QR code / imprimés": "#D9B43E" };
  const day = ts => new Date(ts * 1000).toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });

  function delta(c, p, unit = "%", invert = false) {
    if (c == null || p == null) return "";
    if (unit === "%") {
      if (!p) return c ? `<span class="up">nouveau</span>` : "";
      const v = Math.round((c - p) / p * 100); if (!v) return "stable";
      return `<span class="${(v > 0) !== invert ? "up" : "down"}">${v > 0 ? "▲ +" : "▼ "}${v} %</span> vs période préc.`;
    }
    const v = Math.round((c - p) * 10) / 10; if (!v) return "stable";
    return `<span class="${(v > 0) !== invert ? "up" : "down"}">${v > 0 ? "▲ +" : "▼ "}${String(v).replace(".", ",")} ${unit}</span> vs période préc.`;
  }
  const kpi = (label, value, sub) => `<div class="kpi"><span>${label}</span><b>${value}</b><em>${sub || "&nbsp;"}</em></div>`;
  const MARK = `<svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="rg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff"/><stop offset=".5" stop-color="#C4B5FF"/><stop offset="1" stop-color="#62D4FF"/></linearGradient></defs><g fill="url(#rg)"><path d="M11.5 5.6 L95.5 5.6 L95.5 6.9 L11.5 6.9 Z"/><path d="M19.6 6.9 C21.4 7.4 21.9 9.2 21.9 11.5 L21.9 86 C21.9 91.6 25.6 95.3 32 95.3 L96.5 95.3 L96.5 94 L32 94 C29.1 94 27.4 91.2 27.4 87.4 L27.4 11.5 C27.4 9.2 27.9 7.4 29.7 6.9 Z"/><path d="M89.6 6.9 L95.5 6.9 L28.2 93.2 L27.4 93.2 L27.4 86.2 Z"/></g></svg>`;

  /* Graphique de fréquentation : barres visiteurs + courbe pages vues */
  function chart(series, bucket) {
    const W = 700, H = 180, L = 34, B = 22, n = series.length || 1;
    const max = Math.max(4, ...series.map(s => Math.max(s.visitors, s.pageviews)));
    const step = Math.pow(10, Math.floor(Math.log10(max))), top = Math.ceil(max / step) * step;
    const x = i => L + (W - L - 6) * (i + .5) / n, y = v => (H - B) - (H - B - 8) * v / top, bw = Math.max(2, (W - L - 6) / n * .62);
    let g = "";
    for (let i = 0; i <= 4; i++) { const v = top * i / 4; g += `<line x1="${L}" x2="${W}" y1="${y(v)}" y2="${y(v)}" stroke="#ECEAF6"/><text x="${L - 6}" y="${y(v) + 3}" text-anchor="end" font-size="9" fill="#8A86A3">${nf.format(Math.round(v))}</text>`; }
    const bars = series.map((s, i) => `<rect x="${x(i) - bw / 2}" y="${y(s.visitors)}" width="${bw}" height="${(H - B) - y(s.visitors)}" rx="2" fill="url(#bv)"/>`).join("");
    const line = series.map((s, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(s.pageviews).toFixed(1)}`).join("");
    const every = Math.ceil(n / 8);
    const lab = s => bucket === "day" ? new Date(s.label).toLocaleDateString("fr-FR", { day: "numeric", month: "short" }) : bucket === "month" ? new Date(s.label + "-01").toLocaleDateString("fr-FR", { month: "short", year: "2-digit" }) : s.label;
    const xs = series.map((s, i) => i % every ? "" : `<text x="${x(i)}" y="${H - 6}" text-anchor="middle" font-size="9" fill="#8A86A3">${esc(lab(s))}</text>`).join("");
    return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Fréquentation"><defs><linearGradient id="bv" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6A4DF5"/><stop offset="1" stop-color="#3D6FF0"/></linearGradient></defs>${g}${bars}<path d="${line}" fill="none" stroke="#1FA9D6" stroke-width="2"/>${xs}</svg>`;
  }

  /* Carte de France : points de terre (grille ~0,12°) et villes des visites */
  function franceMap(geo) {
    const W = 360, H = 360, cos = Math.cos(46.4 * Math.PI / 180), k = W / ((9.9 + 5.3) * cos);
    const px = lo => (lo + 5.3) * cos * k, py = la => (51.3 - la) * k * 1.0;
    let dots = "";
    try { for (const [la, lo, v, t] of IZGlobe.land()) if (t === 4) dots += `<circle cx="${px(lo).toFixed(1)}" cy="${py(la).toFixed(1)}" r="1.15" fill="${v > .5 ? "#B9ACFA" : "#D8D1FB"}"/>`; } catch (e) {}
    const G = (geo || []).slice(0, 40), max = Math.max(1, ...G.map(c => c.n));
    const cities = G.map(c => { const r = 3 + 13 * Math.sqrt(c.n / max); return `<circle cx="${px(c.lon).toFixed(1)}" cy="${py(c.lat).toFixed(1)}" r="${r.toFixed(1)}" fill="rgba(106,77,245,.28)" stroke="#6A4DF5" stroke-width="1"/><circle cx="${px(c.lon).toFixed(1)}" cy="${py(c.lat).toFixed(1)}" r="1.6" fill="#15122B"/>`; }).join("");
    const placed = [];
    const labels = G.slice(0, 8).filter(c => { const x = px(c.lon), y = py(c.lat); if (placed.some(([a, b]) => Math.abs(a - x) < 80 && Math.abs(b - y) < 16)) return false; placed.push([x, y]); return true; }).map(c => `<text x="${(px(c.lon) + 6).toFixed(1)}" y="${(py(c.lat) - 6).toFixed(1)}" font-size="10" font-weight="600" fill="#15122B" paint-order="stroke" stroke="#fff" stroke-width="3">${esc(String(c.name).split(" · ")[0])} ${nf.format(c.n)}</text>`).join("");
    return `<svg viewBox="0 0 ${W} ${H * 1.02}" role="img" aria-label="Carte des visites en France">${dots}${cities}${labels}</svg>`;
  }

  function bars(rows, total) {
    if (!rows.length) return `<p class="note">Aucune donnée sur la période.</p>`;
    const max = Math.max(...rows.map(r => r.n));
    return `<ul class="bars">${rows.map(r => `<li><div class="l"><span>${esc(String(r.name).split(" · ")[0])}</span><b>${nf.format(r.n)}${total ? ` · ${pc(r.n / total * 100)}` : ""}</b></div><div class="t"><i style="width:${r.n / max * 100}%"></i></div></li>`).join("")}</ul>`;
  }

  function render(d) {
    const k = d.kpi, p = d.prev, E = d.engagement || {}, Ep = d.engagement_prev || {}, F = d.funnel || {}, H = d.health || {}, M = H.metrics || {};
    const name = d.all ? "Ensemble des sites" : d.site.name, domain = d.all ? "Vue d'ensemble IZENINFO" : d.site.domain;
    const per = `du ${day(d.from)} au ${day(d.to)}`;
    let page = 0; const TOTAL = 4;
    const foot = () => `<footer><span>IZENINFO · Rapport d'audience · ${esc(domain)}</span><span>${++page} / ${TOTAL}</span></footer>`;
    const sum = I.summary(d), rec = I.recos(d);
    const conv = E.visits ? E.converted / E.visits * 100 : null, pconv = F.prev_visits ? F.prev_converted / F.prev_visits * 100 : null;

    /* Page 1 : couverture, chiffres clés, l'essentiel */
    const p1 = `<div class="page"><div class="cover"><div class="brand">${MARK}IZENINFO</div>
      <div class="kicker">Rapport d'audience · ${esc(RANGE[d.range] || "")}</div>
      <h1>${esc(name)}<br><em>${esc(domain)}</em></h1>
      <div class="meta"><span>Période : <b>${per}</b></span><span>Édité le <b>${new Date().toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" })}</b></span></div></div>
      <div class="kpis">
        ${kpi("Visiteurs", nf.format(k.visitors), delta(k.visitors, p.visitors))}
        ${kpi("Pages vues", nf.format(k.pageviews), delta(k.pageviews, p.pageviews))}
        ${kpi("Contacts", nf.format(k.contacts), delta(k.contacts, p.contacts))}
        ${kpi("Taux de contact", pc(conv, 1), conv != null && pconv != null ? delta(Math.round(conv * 10) / 10, Math.round(pconv * 10) / 10, "pt") : "")}
        ${kpi("Temps moyen / visite", I.dur(E.avg_time), Ep.measured >= 5 && E.avg_time != null ? delta(E.avg_time, Ep.avg_time) : "temps onglet visible")}
        ${kpi("Taux de rebond", pc(E.bounce_rate), Ep.visits ? delta(E.bounce_rate, Ep.bounce_rate, "pt", true) : "")}
      </div>
      <section><h2>L'essentiel</h2><p class="lead">Les constats les plus importants de la période, calculés à partir de vos chiffres.</p>
        <ul class="ins">${sum.slice(0, 6).map(x => `<li class="${x.tone}"><span>${x.html}</span></li>`).join("")}</ul></section>
      ${foot()}</div>`;

    /* Page 2 : fréquentation, provenance, entonnoir */
    const ch = d.channels || [], ct = ch.reduce((a, r) => a + r.visits, 0);
    const S = F.steps || [], s0 = S[0] ? S[0].n : 0;
    const p2 = `<div class="page">
      <section><h2>Fréquentation</h2><p class="lead">${esc(RANGE[d.range] || "")}${d.bucket === "hour" ? ", par heure" : d.bucket === "month" ? ", par mois" : ", par jour"}</p>
        <div class="legend"><span><i style="background:#6A4DF5"></i>Visiteurs</span><span><i style="background:#1FA9D6"></i>Pages vues</span></div>
        <div class="chart">${chart(d.series || [], d.bucket)}</div></section>
      <section><h2>Provenance des visites</h2><p class="lead">Par où arrivent vos visiteurs, et lesquels vous contactent.</p>
        <table><thead><tr><th>Canal</th><th>Visites</th><th>Part</th><th>Contacts</th><th>Taux de contact</th></tr></thead><tbody>
        ${ch.length ? ch.slice(0, 7).map(r => `<tr><td><span class="dot" style="background:${CH[r.name] || "#8D9AB3"}"></span>${esc(r.name)}</td><td>${nf.format(r.visits)}</td><td>${ct ? pc(r.visits / ct * 100) : "–"}</td><td>${nf.format(r.contacts)}</td><td>${r.visits ? pc(r.converted / r.visits * 100, 1) : "–"}</td></tr>`).join("") : `<tr><td colspan="5">Aucune visite</td></tr>`}
        </tbody></table></section>
      <section><h2>Entonnoir de contact</h2><p class="lead">Du simple passage à la prise de contact.</p>
        <div class="fun">${S.map((st, i) => `<div class="r"><div class="b"><i style="width:${s0 ? Math.max(5, st.n / s0 * 100) : 5}%"></i><b>${nf.format(st.n)}</b></div>
          <div class="t"><strong>${esc(st.name)}</strong><span>${i ? (S[i - 1].n ? pc(st.n / S[i - 1].n * 100, 1) + " de l'étape précédente · " : "") : ""}${esc(st.hint)}</span></div></div>`).join("")}</div>
        <div class="fval">${kpi("Taux de contact", pc(conv, 1), "")}${kpi("Valeur estimée générée", F.value != null ? I.euro(F.value) : "–", F.value != null ? `${nf.format(E.converted)} contacts × ${I.euro(F.lead_value)}` : "valeur d'un contact non renseignée")}</div></section>
      ${foot()}</div>`;

    /* Page 3 : géographie et pages */
    const geo = d.geo || [], gt = geo.reduce((a, r) => a + r.n, 0);
    const ent = (d.entries || []).slice(0, 6), pg = (d.pages_eng || []).slice(0, 6);
    const p3 = `<div class="page">
      <section><h2>Où sont vos visiteurs</h2><p class="lead">Ville approximative des visites localisées en France (sans adresse IP conservée).</p>
        <div class="map"><div>${franceMap(geo)}</div><div><h3 style="font-size:10.5pt;margin:0 0 3mm">Villes principales</h3>${bars(geo.slice(0, 8), gt)}</div></div></section>
      <section><h2>Pages d'entrée</h2><p class="lead">La première page vue à chaque visite : rebond et taux de contact.</p>
        <table><thead><tr><th>Page</th><th>Visites</th><th>Rebond</th><th>Contacts</th><th>Taux de contact</th></tr></thead><tbody>
        ${ent.length ? ent.map(r => `<tr><td class="p">${esc(r.name)}</td><td>${nf.format(r.n)}</td><td>${pc(r.bounce)}</td><td>${nf.format(r.contacts)}</td><td>${r.contacts ? pc(r.rate, 1) : "–"}</td></tr>`).join("") : `<tr><td colspan="5">Aucune donnée</td></tr>`}
        </tbody></table></section>
      <section><h2>Pages les plus consultées</h2>
        <table><thead><tr><th>Page</th><th>Vues</th><th>Temps moyen</th><th>Lecture</th><th>Sortie</th></tr></thead><tbody>
        ${pg.length ? pg.map(r => `<tr><td class="p">${esc(r.name)}</td><td>${nf.format(r.views)}</td><td>${r.time != null ? I.dur(r.time) : "–"}</td><td>${r.scroll != null ? r.scroll + " %" : "–"}</td><td>${pc(r.exit)}</td></tr>`).join("") : `<tr><td colspan="5">Aucune donnée</td></tr>`}
        </tbody></table></section>
      ${foot()}</div>`;

    /* Page 4 : santé, recommandations */
    const sc = H.score, C = 2 * Math.PI * 52, col = sc == null ? "#C9C5E6" : sc >= 75 ? "#3DBA78" : sc >= 50 ? "#E8B04A" : "#E5664F";
    const fmt = (key, v) => key === "cls" ? String(Math.round(v * 100) / 100).replace(".", ",") : v >= 1000 ? String(Math.round(v / 100) / 10).replace(".", ",") + " s" : Math.round(v) + " ms";
    const lab = { good: "Bon", ni: "À améliorer", poor: "Lent" };
    const HM = [["lcp", "Affichage du contenu (LCP)"], ["inp", "Réactivité (INP)"], ["cls", "Stabilité visuelle (CLS)"], ["ttfb", "Réponse du serveur (TTFB)"]];
    const p4 = `<div class="page">
      <section><h2>Santé du site</h2><p class="lead">Vitesse mesurée chez vos vrais visiteurs (75e centile${H.pages ? `, ${nf.format(H.pages)} pages mesurées` : ""}) et erreurs rencontrées.</p>
        <div class="health"><div class="ring"><svg viewBox="0 0 120 120"><circle cx="60" cy="60" r="52" fill="none" stroke="#EEECF8" stroke-width="10"/><circle cx="60" cy="60" r="52" fill="none" stroke="${col}" stroke-width="10" stroke-linecap="round" stroke-dasharray="${sc != null ? C * sc / 100 : 0} ${C}"/></svg>
          <div><b>${sc != null ? sc : "–"}</b><span>${sc == null ? "en attente" : sc >= 90 ? "Excellent" : sc >= 75 ? "Bon" : sc >= 50 ? "À améliorer" : "Lent"}</span></div></div>
        <div class="hm">${HM.map(([key, t]) => { const m = M[key]; if (!m) return `<div><span>${t}</span><b>–</b><small>pas encore de mesure</small></div>`; const r = I.rate(key, m.p75);
          return `<div class="${r}"><span>${t}</span><b>${fmt(key, m.p75)}<em>${lab[r]}</em></b><div class="tri"><i class="g" style="width:${m.good}%"></i><i class="n" style="width:${m.ni}%"></i><i class="p" style="width:${m.poor}%"></i></div><small>${m.good} % bon · ${m.ni} % à améliorer · ${m.poor} % lent</small></div>`; }).join("")}</div></div>
        ${(H.errors || []).length ? `<ul class="errs">${H.errors.slice(0, 6).map(e => `<li><code>${esc(e.name)}</code> — erreur ${e.status}, ${nf.format(e.n)} fois</li>`).join("")}</ul>` : `<p class="note" style="margin-top:4mm">Aucune page en erreur rencontrée par vos visiteurs sur la période.</p>`}</section>
      <section><h2>Recommandations</h2><p class="lead">Les actions prioritaires pour la période à venir.</p>
        <ol class="recos">${rec.map(r => `<li><div><strong>${esc(r.title)}</strong><span>${esc(r.text)}</span></div></li>`).join("")}</ol></section>
      <p class="note">Méthode : mesure d'audience sans cookie, hébergée en France ; aucune adresse IP n'est conservée. Une visite = un visiteur sur une journée. Rebond = visite d'une seule page sans contact. Contact = clic sur le téléphone ou l'e-mail, ou formulaire envoyé. Temps passé = durée onglet visible. Vitesse : Core Web Vitals mesurés dans le navigateur des visiteurs. Géolocalisation approximative : IP Geolocation by DB-IP.</p>
      <div class="signoff"><span><b>IZENINFO</b> · Studio de conception de sites web · izeninfo.fr</span><span>Une question sur ce rapport ? <b>direction@izenride.com</b></span></div>
      ${foot()}</div>`;

    $("doc").innerHTML = p1 + p2 + p3 + p4;
    document.title = `Rapport ${domain} · ${RANGE[d.range] || ""} · IZENINFO`;
    $("state").remove();
    $("bPrint").disabled = false;
  }

  $("bPrint").addEventListener("click", () => window.print());
  $("bClose").addEventListener("click", () => { window.close(); setTimeout(() => { location.href = "./"; }, 200); });

  fetch(`api.php?a=stats&site=${encodeURIComponent(site)}&range=${encodeURIComponent(range)}`, { credentials: "same-origin", headers: { Accept: "application/json" } })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      if (!ok || j.error) { $("state").innerHTML = `${esc(j.error || "Impossible de charger les statistiques.")} <a href="./" style="color:#62D4FF">Se connecter à l'espace client</a>`; return; }
      render(j);
      if (q.get("print") === "1") setTimeout(() => window.print(), 600);
    })
    .catch(() => { $("state").textContent = "Connexion impossible. Réessayez dans un instant."; });
})();
