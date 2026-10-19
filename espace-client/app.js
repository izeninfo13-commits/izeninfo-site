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
    const sel = $("siteSel");
    sel.innerHTML = state.sites.map(x => `<option value="${x.id}">${esc(x.name)} · ${esc(x.domain)}</option>`).join("");
    sel.hidden = state.sites.length < 2;
    let saved = null; try { saved = localStorage.getItem("izs-site"); } catch (e) {}
    state.site = state.sites.find(x => String(x.id) === saved) || state.sites[0] || null;
    if (state.site) sel.value = state.site.id;
    showView("stats");
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
    state.site = state.sites.find(x => String(x.id) === e.target.value);
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
    if (!state.site) {
      $("siteTitle").firstChild.textContent = "Aucun site";
      $("siteDomain").textContent = "Votre accès n'est rattaché à aucun site pour le moment.";
      return;
    }
    $("siteTitle").firstChild.textContent = state.site.name;
    $("siteDomain").textContent = state.site.domain;
    loadStats(); loadLive();
    liveTimer = setInterval(() => { if (!document.hidden) loadLive(); }, 10000);
    statsTimer = setInterval(() => { if (!document.hidden) loadStats(true); }, 60000);
  }

  async function loadLive() {
    try {
      const l = await api("live", null, `&site=${state.site.id}`);
      $("liveN").textContent = nf.format(l.count);
      $("liveLbl").textContent = l.count > 1 ? "visiteurs sur le site" : "visiteur sur le site";
      $("livePages").innerHTML = l.pages.slice(0, 4).map(p => `<li><span>${esc(p.path)}</span><span>${p.n}</span></li>`).join("");
    } catch (e) {}
  }

  async function loadStats(quiet) {
    try {
      const d = await api("stats", null, `&site=${state.site.id}&range=${state.range}`);
      state.data = d; render(d);
      $("updated").textContent = "Mis à jour à " + new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
    } catch (e) { if (!quiet) $("updated").textContent = e.message; }
  }

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
    $("kV").textContent = nf.format(k.visitors); $("dV").innerHTML = delta(k.visitors, p.visitors);
    $("kP").textContent = nf.format(k.pageviews); $("dP").innerHTML = delta(k.pageviews, p.pageviews);
    $("kR").textContent = String(k.ppv).replace(".", ","); $("dR").innerHTML = delta(k.ppv, p.ppv);
    $("kC").textContent = nf.format(k.contacts); $("dC").innerHTML = delta(k.contacts, p.contacts);
    const empty = !k.pageviews && !p.pageviews;
    $("install").hidden = !empty;
    if (empty) $("installCode").textContent = `<script defer src="https://izeninfo.fr/stats/t.js" data-site="${state.site.skey}"></script>`;
    const labels = { today: "Aujourd'hui, par heure", "7d": "7 derniers jours", "30d": "30 derniers jours", "12m": "12 derniers mois, par mois" };
    $("trafficSub").textContent = labels[d.range] || "";
    chart(d);
    table(d);
    const visits = d.devices.reduce((a, r) => a + r.n, 0);
    bars("lSources", d.sources, false, "Aucune visite sur la période");
    bars("lPages", d.pages, false, "Aucune page vue sur la période");
    bars("lDevices", d.devices, visits, "Aucune donnée");
    bars("lCountries", d.countries, visits, "Aucune donnée");
    bars("lBrowsers", d.browsers, visits, "Aucune donnée");
    $("eTel").textContent = nf.format(d.events.tel); $("eMail").textContent = nf.format(d.events.mail); $("eForm").textContent = nf.format(d.events.form);
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
    hit.addEventListener("pointerleave", () => { hov.setAttribute("visibility", "hidden"); tip.hidden = true; });
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
      box.innerHTML = d.users.map(u => {
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
    const b = e.target.closest("button"); if (!b) return;
    const card = b.closest(".client"), out = card && card.querySelector(".out");
    if (b.dataset.copy) { try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = "Copié"; setTimeout(() => b.textContent = "Copier le code", 1500); } catch (err) {} return; }
    if (b.dataset.reset) {
      try { const r = await api("reset_password", { user_id: +b.dataset.reset }); out.innerHTML = `<div class="secret"><p><strong>Nouveau mot de passe :</strong></p><span class="code">${esc(r.password)}</span><p class="meta" style="margin-top:8px">Il ne sera plus affiché : transmettez-le maintenant au client.</p></div>`; }
      catch (err) { out.innerHTML = `<p class="err">${esc(err.message)}</p>`; }
      return;
    }
    if (b.dataset.del) {
      if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = "Confirmer la suppression (données comprises)"; return; }
      try { await api("delete_client", { user_id: +b.dataset.del }); loadAdmin(); state.sites = (await api("state")).sites; }
      catch (err) { out.innerHTML = `<p class="err">${esc(err.message)}</p>`; }
    }
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
      const sel = $("siteSel"); sel.innerHTML = state.sites.map(x => `<option value="${x.id}">${esc(x.name)} · ${esc(x.domain)}</option>`).join(""); sel.hidden = state.sites.length < 2;
      if (state.site) sel.value = state.site.id;
      loadAdmin();
    } catch (err) { $("cErr").textContent = err.message; }
  });

  boot().catch(e => { document.body.insertAdjacentHTML("afterbegin", `<p class="err" style="padding:20px">${esc(e.message)}</p>`); });
})();
