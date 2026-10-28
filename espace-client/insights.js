/* IZENINFO – Espace client : résumé intelligent et recommandations, calculés à partir des chiffres.
   Partagé par le tableau de bord (app.js) et le rapport PDF (rapport.js). Aucun appel externe. */
(() => {
  "use strict";
  const nf = new Intl.NumberFormat("fr-FR");
  const pc = (v, d = 0) => (Math.round(v * Math.pow(10, d)) / Math.pow(10, d)).toLocaleString("fr-FR") + " %";
  const dur = s => s == null ? "–" : s < 60 ? `${s} s` : `${Math.floor(s / 60)} min${s % 60 ? " " + String(s % 60).padStart(2, "0") + " s" : ""}`;
  const euro = v => new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v);
  const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const page = p => { const s = String(p || "/"); return s === "/" ? "d'accueil" : `« ${esc(s.length > 42 ? s.slice(0, 40) + "…" : s)} »`; };
  const evo = (c, p) => p ? Math.round((c - p) / p * 100) : null;
  const RANGE = { today: "aujourd'hui", "7d": "ces 7 derniers jours", "30d": "ces 30 derniers jours", "90d": "ces 90 derniers jours", "12m": "ces 12 derniers mois" };
  const TH = { lcp: [2500, 4000], inp: [200, 500], cls: [.1, .25], ttfb: [800, 1800], fcp: [1800, 3000] };
  const rate = (k, v) => v == null ? null : v <= TH[k][0] ? "good" : v <= TH[k][1] ? "ni" : "poor";

  /* Résumé : 3 à 6 constats classés par importance. tone = up | down | info | warn */
  function summary(d) {
    const out = [], k = d.kpi || {}, p = d.prev || {}, E = d.engagement || {}, Ep = d.engagement_prev || {}, F = d.funnel || {};
    const add = (w, tone, html) => out.push({ w, tone, html });
    if (!k.pageviews) return [{ tone: "info", html: "Aucune visite enregistrée sur la période pour le moment." }];

    const ev = evo(k.visitors, p.visitors);
    if (ev === null) add(10, "info", `<b>${nf.format(k.visitors)} visiteurs</b> ${RANGE[d.range] || "sur la période"}.`);
    else if (Math.abs(ev) < 5) add(6, "info", `Fréquentation <b>stable</b> : ${nf.format(k.visitors)} visiteurs (${nf.format(p.visitors)} sur la période précédente).`);
    else add(10, ev > 0 ? "up" : "down", `Fréquentation <b>${ev > 0 ? "en hausse" : "en baisse"} de ${Math.abs(ev)} %</b> : ${nf.format(k.visitors)} visiteurs contre ${nf.format(p.visitors)} sur la période précédente.`);

    /* Contacts et valeur */
    if (k.contacts || p.contacts) {
      const ec = evo(E.converted ?? k.contacts, F.prev_converted ?? p.contacts);
      const cr = E.visits ? E.converted / E.visits * 100 : 0;
      let t = `<b>${nf.format(E.converted ?? k.contacts)} visiteur${(E.converted ?? k.contacts) > 1 ? "s" : ""} ${((E.converted ?? k.contacts) > 1) ? "vous ont" : "vous a"} contacté</b> (taux de contact ${pc(cr, 1)})`;
      if (ec !== null && Math.abs(ec) >= 5) t += `, ${ec > 0 ? "+" : ""}${ec} % vs période précédente`;
      if (F.value) t += ` · valeur estimée <b>${euro(F.value)}</b>`;
      add(9, ec === null ? "info" : ec >= 0 ? "up" : "down", t + ".");
    } else if (k.visitors >= 30) add(8, "warn", `<b>Aucun contact</b> sur la période malgré ${nf.format(k.visitors)} visiteurs : le parcours vers l'appel ou le formulaire est à revoir.`);

    /* Canal principal et canal le plus rentable */
    const ch = (d.channels || []).filter(r => r.visits);
    const tot = ch.reduce((a, r) => a + r.visits, 0);
    if (ch.length && tot) {
      const top = ch[0], best = ch.filter(r => r.visits >= 10 && r.converted).sort((a, b) => b.converted / b.visits - a.converted / a.visits)[0];
      let t = `<b>${esc(top.name)}</b> est votre première source de visites (${pc(top.visits / tot * 100)})`;
      if (best && best.name !== top.name) t += ` ; les visiteurs venus de <b>${esc(best.name)}</b> contactent le plus (${pc(best.converted / best.visits * 100, 1)})`;
      else if (best) t += `, et c'est aussi celle qui convertit le mieux (${pc(best.converted / best.visits * 100, 1)})`;
      add(7, "info", t + ".");
    }

    /* Engagement */
    if (E.measured >= 10 && E.avg_time != null) {
      const eb = Ep.visits ? Math.round((E.bounce_rate - Ep.bounce_rate) * 10) / 10 : null;
      let t = `Les visiteurs restent en moyenne <b>${dur(E.avg_time)}</b> et lisent ${E.scroll != null ? pc(E.scroll) : "–"} des pages ; <b>${pc(E.bounce_rate)}</b> repartent après une seule page`;
      if (eb !== null && Math.abs(eb) >= 3 && Ep.measured) t += ` (${eb > 0 ? "+" : ""}${String(eb).replace(".", ",")} pt)`;
      add(6, E.bounce_rate > 60 ? "warn" : "info", t + ".");
    } else if (E.visits) add(3, "info", `Taux de rebond : <b>${pc(E.bounce_rate)}</b> des visites s'arrêtent à une seule page sans contact.`);

    /* Pages d'entrée : la plus efficace, celle qui fait fuir */
    const en = (d.entries || []).filter(r => r.n >= 10);
    const bestP = en.filter(r => r.contacts).sort((a, b) => b.rate - a.rate)[0];
    const leak = en.filter(r => r.bounce >= 55).sort((a, b) => b.n - a.n)[0];
    if (bestP) add(5, "up", `La page ${page(bestP.name)} transforme le mieux : <b>${pc(bestP.rate, 1)}</b> des visiteurs qui y arrivent vous contactent.`);
    if (leak) add(5, "warn", `La page ${page(leak.name)} attire ${nf.format(leak.n)} visites mais <b>${pc(leak.bounce)}</b> repartent aussitôt.`);

    /* Santé */
    const H = d.health || {};
    if (H.score != null) {
      const lab = H.score >= 90 ? "excellente" : H.score >= 75 ? "bonne" : H.score >= 50 ? "moyenne" : "faible";
      let t = `Santé technique <b>${lab} (${H.score}/100)</b>`;
      const lcp = H.metrics && H.metrics.lcp;
      if (lcp) t += ` : contenu principal affiché en ${String(Math.round(lcp.p75 / 100) / 10).replace(".", ",")} s pour 3 visiteurs sur 4`;
      if (H.errors && H.errors.length) t += `, ${H.errors.reduce((a, r) => a + r.n, 0)} page${H.errors.length > 1 ? "s" : ""} en erreur rencontrée${H.errors.length > 1 ? "s" : ""}`;
      add(4, H.score >= 75 && !(H.errors || []).length ? "up" : "warn", t + ".");
    }

    /* Mobile et géographie */
    const dv = d.devices || [], dvt = dv.reduce((a, r) => a + r.n, 0), mob = dv.find(r => r.name === "Mobile");
    if (mob && dvt && mob.n / dvt > .55) add(3, "info", `<b>${pc(mob.n / dvt * 100)}</b> des visites se font sur mobile.`);
    const geo = d.geo || [], gt = geo.reduce((a, r) => a + r.n, 0);
    if (geo[0] && gt >= 10) add(2, "info", `<b>${esc(String(geo[0].name).split(" · ")[0])}</b> concentre ${pc(geo[0].n / gt * 100)} des visites localisées en France.`);

    return out.sort((a, b) => b.w - a.w).slice(0, 6);
  }

  /* Recommandations concrètes, par ordre de priorité */
  function recos(d) {
    const r = [], E = d.engagement || {}, H = d.health || {}, M = H.metrics || {}, F = d.funnel || {}, k = d.kpi || {};
    const add = (w, title, text) => r.push({ w, title, text });
    const steps = (F.steps || []).map(s => s.n);
    if ((H.errors || []).length) add(9, "Corriger les pages en erreur", `Des visiteurs tombent sur des pages introuvables (${H.errors.slice(0, 3).map(e => e.name).join(", ")}). Une redirection vers la bonne page évite de perdre ces visites.`);
    if (M.lcp && M.lcp.p75 > 2500) add(8, "Accélérer l'affichage", `Le contenu principal met ${String(Math.round(M.lcp.p75 / 100) / 10).replace(".", ",")} s à s'afficher (objectif : moins de 2,5 s). Compresser les images (WebP/AVIF), différer les scripts secondaires et alléger la première section.`);
    if (M.lcp_mobile && M.lcp_mobile.p75 > 3000) add(7, "Priorité au mobile", `Sur mobile, l'affichage est plus lent (${String(Math.round(M.lcp_mobile.p75 / 100) / 10).replace(".", ",")} s). Adapter la taille des images aux petits écrans.`);
    if (M.cls && M.cls.p75 > .1) add(6, "Stabiliser la mise en page", "Des éléments bougent pendant le chargement (images sans dimensions, bandeaux insérés). Réserver leur place évite les clics manqués.");
    if (M.inp && M.inp.p75 > 200) add(6, "Rendre les boutons plus réactifs", "Les clics mettent du temps à produire un effet : alléger les scripts exécutés au clic (menus, carrousels, outils tiers).");
    if (E.visits >= 30 && E.bounce_rate > 55) add(7, "Retenir dès la première page", `${String(E.bounce_rate).replace(".", ",")} % des visites s'arrêtent à une page. Mettre l'offre et un bouton d'appel visibles sans défiler, et proposer un lien vers les réalisations ou les tarifs.`);
    if (steps.length === 4 && steps[2] >= 15 && steps[3] / steps[2] < .2) add(8, "Faciliter la prise de contact", `${nf.format(steps[2])} visites ont montré un intérêt commercial mais seulement ${nf.format(steps[3])} ont abouti à un contact. Raccourcir le formulaire, afficher le téléphone cliquable et un délai de réponse.`);
    if (k.visitors >= 30 && !k.contacts) add(9, "Créer des occasions de contact", "Aucun appel, e-mail ou formulaire sur la période : ajouter des boutons d'appel et de devis sur chaque page, notamment sur mobile.");
    const ch = d.channels || [], tot = ch.reduce((a, x) => a + x.visits, 0), maps = ch.find(x => x.name === "Fiche Google (Maps)");
    if (tot >= 50 && (!maps || maps.visits / tot < .05)) add(5, "Développer la fiche Google", "La fiche Google apporte peu de visites : publier des photos et des actualités, répondre aux avis et ajouter le lien du site suivi (?utm_source=google&utm_medium=gbp).");
    const g = ch.find(x => x.name === "Google (naturel)");
    if (tot >= 50 && (!g || g.visits / tot < .2)) add(5, "Renforcer le référencement naturel", "Google amène encore peu de visiteurs : enrichir les pages services avec les villes ciblées et publier régulièrement du contenu utile.");
    if (!F.lead_value && !d.all) add(2, "Renseigner la valeur d'un contact", "Indiquez dans l'entonnoir la valeur moyenne d'un contact pour suivre la valeur générée par le site en euros.");
    if (!r.length) add(1, "Maintenir le cap", "Les indicateurs sont bons : continuer à publier, suivre l'évolution des contacts et tester une nouvelle page d'offre.");
    return r.sort((a, b) => b.w - a.w).slice(0, 5);
  }

  window.IZInsights = { summary, recos, dur, euro, rate, TH };
})();
