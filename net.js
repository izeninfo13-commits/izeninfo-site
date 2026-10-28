/* Fond réseau : points platine reliés, animation lente, réagit à la souris */
(() => {
  const c = document.getElementById("net"); if (!c || !c.getContext) return;
  const x = c.getContext("2d"), still = matchMedia("(prefers-reduced-motion: reduce)").matches;
  let W, H, dpr, pts = [], mx = -999, my = -999, run = true;
  const size = () => { dpr = Math.min(devicePixelRatio || 1, 1.5); W = innerWidth; H = innerHeight; c.width = W * dpr; c.height = H * dpr; x.setTransform(dpr, 0, 0, dpr, 0, 0);
    const n = Math.round(Math.min(170, Math.max(36, W * H / 9500)));
    pts = Array.from({ length: n }, () => ({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - .5) * .16, vy: (Math.random() - .5) * .16, chip: Math.random() < .1 })); };
  const D = 165;
  const draw = () => {
    x.clearRect(0, 0, W, H);
    for (let i = 0; i < pts.length; i++) { const a = pts[i];
      if (!still) { a.x += a.vx; a.y += a.vy; if (a.x < -20) a.x = W + 20; if (a.x > W + 20) a.x = -20; if (a.y < -20) a.y = H + 20; if (a.y > H + 20) a.y = -20; }
      for (let j = i + 1; j < pts.length; j++) { const b = pts[j], dx = a.x - b.x, dy = a.y - b.y, d = dx * dx + dy * dy;
        if (d < D * D) { const o = (1 - Math.sqrt(d) / D) * .52; x.strokeStyle = (i + j) % 4 ? `rgba(92,124,180,${o})` : `rgba(201,162,78,${o})`; x.lineWidth = .7; x.beginPath();
          /* tracé en équerre, façon circuit, pour une partie des liaisons */
          if ((i + j) % 3 === 0) { x.moveTo(a.x, a.y); x.lineTo(b.x, a.y); x.lineTo(b.x, b.y); } else { x.moveTo(a.x, a.y); x.lineTo(b.x, b.y); }
          x.stroke(); } }
      const dm = Math.hypot(a.x - mx, a.y - my);
      if (dm < 190) { x.strokeStyle = `rgba(230,200,134,${(1 - dm / 190) * .55})`; x.lineWidth = .8; x.beginPath(); x.moveTo(a.x, a.y); x.lineTo(mx, my); x.stroke(); }
      x.fillStyle = a.chip ? "rgba(232,205,132,.95)" : "rgba(150,175,215,.85)";
      if (a.chip) { x.fillRect(a.x - 2.5, a.y - 2.5, 5, 5); x.strokeStyle = "rgba(201,162,78,.55)"; x.strokeRect(a.x - 5.5, a.y - 5.5, 11, 11); }
      else { x.beginPath(); x.arc(a.x, a.y, 1.6, 0, 6.283); x.fill(); }
    }
    if (run && !still) requestAnimationFrame(draw);
  };
  size(); addEventListener("resize", () => { size(); if (still) draw(); });
  addEventListener("pointermove", e => { mx = e.clientX; my = e.clientY; }, { passive: true });
  addEventListener("pointerleave", () => { mx = my = -999; });
  document.addEventListener("visibilitychange", () => { const v = !document.hidden; if (v && !run) { run = true; requestAnimationFrame(draw); } else run = v; });
  draw();
})();
