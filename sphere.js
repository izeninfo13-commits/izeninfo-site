/* IZENINFO – fond « sphère de données » : particules WebGL qui changent de forme selon la section affichée.
   Sans dépendance. Formes : 0 sphère, 1 grille, 2 réseau, 3 nom IZENINFO, 4 anneau. */
(() => {
  const cv = document.getElementById("sphere"); if (!cv) return;
  const gl = cv.getContext("webgl", { antialias: false, alpha: true, premultipliedAlpha: false, powerPreference: "low-power" });
  if (!gl) { cv.remove(); return; }
  const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
  const small = Math.min(innerWidth, innerHeight) < 700;
  const N = small ? 2600 : 4800;

  /* ---- Formes ---- */
  const shapes = [0, 1, 2, 3, 4].map(() => new Float32Array(N * 3));
  for (let i = 0; i < N; i++) { // sphère de Fibonacci
    const y = 1 - (i / (N - 1)) * 2, r = Math.sqrt(1 - y * y), t = i * 2.39996;
    shapes[0].set([Math.cos(t) * r * 2.3, y * 2.3, Math.sin(t) * r * 2.3], i * 3);
  }
  const side = Math.ceil(Math.sqrt(N));
  for (let i = 0; i < N; i++) { // grille ondulée
    const gx = (i % side) / side - .5, gz = Math.floor(i / side) / side - .5;
    shapes[1].set([gx * 8, Math.sin(gx * 9) * .28 + Math.cos(gz * 9) * .28 - .2, gz * 8], i * 3);
  }
  let seed = 7; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
  const hubs = Array.from({ length: 16 }, () => [(rnd() - .5) * 7, (rnd() - .5) * 3.8, (rnd() - .5) * 3]);
  for (let i = 0; i < N; i++) { // réseau d'amas
    const h = hubs[i % hubs.length], a = rnd() * 6.283, b = Math.acos(2 * rnd() - 1), r = Math.pow(rnd(), 2.2) * .95;
    shapes[2].set([h[0] + r * Math.sin(b) * Math.cos(a), h[1] + r * Math.cos(b), h[2] + r * Math.sin(b) * Math.sin(a)], i * 3);
  }
  for (let i = 0; i < N; i++) { // anneau
    const a = (i / N) * 6.283 * 7, R = 2.4 + Math.sin(a * 3) * .12, tt = (rnd() - .5) * .5;
    shapes[4].set([Math.cos(a / 7) * (R + tt), Math.sin(a) * .18, Math.sin(a / 7) * (R + tt)], i * 3);
  }
  const textShape = () => { // nom échantillonné sur un canvas 2D
    const c = document.createElement("canvas"); c.width = 900; c.height = 170; const x = c.getContext("2d");
    x.fillStyle = "#fff"; x.font = '800 150px Syne, "Manrope", sans-serif'; x.textAlign = "center"; x.textBaseline = "middle"; x.fillText("IZENINFO", 450, 90);
    const d = x.getImageData(0, 0, 900, 170).data, pix = [];
    for (let y = 0; y < 170; y += 2) for (let X = 0; X < 900; X += 2) if (d[(y * 900 + X) * 4 + 3] > 128) pix.push([X, y]);
    for (let i = 0; i < N; i++) { const p = pix[(i * 7919) % pix.length] || [450, 85]; shapes[3].set([(p[0] - 450) / 900 * 6.2, -(p[1] - 85) / 900 * 6.2 + 1.5, (rnd() - .5) * .25], i * 3); }
  };
  textShape(); if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { textShape(); if (typeof refresh === "function") refresh(); });

  /* ---- Couleurs : violet → bleu → cyan ---- */
  const col = new Float32Array(N * 3), A = [.48, .36, 1], B = [.24, .48, 1], C = [.38, .83, 1];
  for (let i = 0; i < N; i++) { const t = (i * 0.618) % 1, [p, q, k] = t < .5 ? [A, B, t * 2] : [B, C, (t - .5) * 2]; col.set([p[0] + (q[0] - p[0]) * k, p[1] + (q[1] - p[1]) * k, p[2] + (q[2] - p[2]) * k], i * 3); }

  /* ---- Shaders ---- */
  const vs = `attribute vec3 p;attribute vec3 p1;attribute vec3 c;uniform mat4 m;uniform float s;uniform float d;uniform float t;varying vec3 vc;varying float vf;
void main(){float e=t<0.5?4.0*t*t*t:1.0-pow(-2.0*t+2.0,3.0)/2.0;vec4 q=m*vec4(mix(p,p1,e),1.0);gl_Position=q;gl_PointSize=s*d/q.w;vc=c;vf=clamp(1.2-q.w/14.0,0.25,1.0);}`;
  const fs = `precision mediump float;varying vec3 vc;varying float vf;uniform float a;
void main(){vec2 u=gl_PointCoord-0.5;float r=dot(u,u);if(r>0.25)discard;float k=smoothstep(0.25,0.0,r);gl_FragColor=vec4(vc*(0.55+k)*1.25,k*a*vf);}`;
  const sh = (t, s) => { const o = gl.createShader(t); gl.shaderSource(o, s); gl.compileShader(o); return o; };
  const pr = gl.createProgram(); gl.attachShader(pr, sh(gl.VERTEX_SHADER, vs)); gl.attachShader(pr, sh(gl.FRAGMENT_SHADER, fs)); gl.linkProgram(pr);
  if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) { cv.remove(); return; }
  gl.useProgram(pr);
  const pos = new Float32Array(shapes[0]);
  const bp = gl.createBuffer(), bc = gl.createBuffer(), b1 = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, bc); gl.bufferData(gl.ARRAY_BUFFER, col, gl.STATIC_DRAW);
  const lc = gl.getAttribLocation(pr, "c"); gl.enableVertexAttribArray(lc); gl.vertexAttribPointer(lc, 3, gl.FLOAT, false, 0, 0);
  gl.bindBuffer(gl.ARRAY_BUFFER, bp); gl.bufferData(gl.ARRAY_BUFFER, pos, gl.DYNAMIC_DRAW);
  const lp = gl.getAttribLocation(pr, "p"); gl.enableVertexAttribArray(lp); gl.vertexAttribPointer(lp, 3, gl.FLOAT, false, 0, 0);
  gl.bindBuffer(gl.ARRAY_BUFFER, b1); gl.bufferData(gl.ARRAY_BUFFER, shapes[0], gl.DYNAMIC_DRAW);
  const l1 = gl.getAttribLocation(pr, "p1"); gl.enableVertexAttribArray(l1); gl.vertexAttribPointer(l1, 3, gl.FLOAT, false, 0, 0);
  const ut = gl.getUniformLocation(pr, "t");
  const um = gl.getUniformLocation(pr, "m"), us = gl.getUniformLocation(pr, "s"), ud = gl.getUniformLocation(pr, "d"), ua = gl.getUniformLocation(pr, "a");
  gl.enable(gl.BLEND); gl.blendFunc(gl.SRC_ALPHA, gl.ONE); gl.clearColor(0, 0, 0, 0);

  /* ---- Matrices ---- */
  const mul = (a, b) => { const o = new Float32Array(16); for (let i = 0; i < 4; i++) for (let j = 0; j < 4; j++) { let s = 0; for (let k = 0; k < 4; k++) s += a[k * 4 + j] * b[i * 4 + k]; o[i * 4 + j] = s; } return o; };
  const persp = (f, asp, n, fr) => { const t = 1 / Math.tan(f / 2); return new Float32Array([t / asp, 0, 0, 0, 0, t, 0, 0, 0, 0, (fr + n) / (n - fr), -1, 0, 0, 2 * fr * n / (n - fr), 0]); };
  const rotY = a => new Float32Array([Math.cos(a), 0, -Math.sin(a), 0, 0, 1, 0, 0, Math.sin(a), 0, Math.cos(a), 0, 0, 0, 0, 1]);
  const rotX = a => new Float32Array([1, 0, 0, 0, 0, Math.cos(a), Math.sin(a), 0, 0, -Math.sin(a), Math.cos(a), 0, 0, 0, 0, 1]);
  const trans = (x, y, z) => new Float32Array([1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, x, y, z, 1]);

  /* ---- État ---- */
  let W, H, dpr, target = 0, ox = 0, oxT = 0, alpha = 1, alphaT = 1, mx = 0, my = 0, run = true, last = performance.now(), spin = 0;
  const size = () => { dpr = Math.min(devicePixelRatio || 1, small ? 1.5 : 1.75); W = innerWidth; H = innerHeight; cv.width = W * dpr; cv.height = H * dpr; gl.viewport(0, 0, cv.width, cv.height); };
  addEventListener("resize", size); size();
  addEventListener("pointermove", e => { mx = e.clientX / W - .5; my = e.clientY / H - .5; }, { passive: true });
  document.addEventListener("visibilitychange", () => { run = !document.hidden; if (run) { last = performance.now(); requestAnimationFrame(frame); } });

  let mt = 1; const ease = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  const setTarget = n => {
    if (n === target) return;
    const e = ease(Math.min(1, mt)), to = shapes[target];
    for (let i = 0; i < N * 3; i++) pos[i] += (to[i] - pos[i]) * e;   // position actuelle → nouveau départ
    gl.bindBuffer(gl.ARRAY_BUFFER, bp); gl.bufferSubData(gl.ARRAY_BUFFER, 0, pos);
    gl.bindBuffer(gl.ARRAY_BUFFER, b1); gl.bufferSubData(gl.ARRAY_BUFFER, 0, shapes[n]);
    target = n; mt = reduce ? 1 : 0;
  };
  /* Forme et position selon la section la plus visible : data-shape="0..4", data-side="right|center", data-dim="0..1" */
  const secs = [...document.querySelectorAll("[data-shape]")];
  const pick = () => {
    let best = null, bv = 0;
    for (const s of secs) { const r = s.getBoundingClientRect(), v = Math.max(0, Math.min(r.bottom, H) - Math.max(r.top, 0)); if (v > bv) { bv = v; best = s; } }
    if (!best) return;
    setTarget(+best.dataset.shape || 0);
    oxT = best.dataset.side === "right" && W > 960 ? 2.0 : 0;
    alphaT = best.dataset.dim ? +best.dataset.dim : .55;
    if (reduce) { mt = 1; ox = oxT; alpha = alphaT; draw(); }
  };
  addEventListener("scroll", pick, { passive: true }); addEventListener("resize", pick);

  const draw = () => {
    const asp = W / H, z = W < 700 ? -10 : -7.6;
    const model = mul(trans(ox, 0, z), mul(rotX(my * .35 + (target === 1 ? .38 : 0)), rotY(spin + mx * .6)));
    gl.uniformMatrix4fv(um, false, mul(persp(.73, asp, .1, 100), model));
    gl.uniform1f(us, (small ? 0.1 : 0.085) * H * .5); gl.uniform1f(ud, dpr); gl.uniform1f(ua, alpha);
    gl.uniform1f(ut, Math.min(1, mt));
    gl.clear(gl.COLOR_BUFFER_BIT); gl.drawArrays(gl.POINTS, 0, N);
  };
  function frame(now) {
    if (!run) return;
    requestAnimationFrame(frame);
    const dt = Math.min(50, now - last); last = now;
    const k = 1 - Math.pow(.94, dt / 16.7); if (mt < 1) mt += dt / 1600;
    ox += (oxT - ox) * k; alpha += (alphaT - alpha) * k;
    spin = target === 3 ? spin * .96 : spin + dt * .00012;
    draw();
  }
  var refresh = () => { if (target === 3) { gl.bindBuffer(gl.ARRAY_BUFFER, b1); gl.bufferSubData(gl.ARRAY_BUFFER, 0, shapes[3]); } };
  pick();
  if (reduce) draw(); else requestAnimationFrame(frame);
})();
