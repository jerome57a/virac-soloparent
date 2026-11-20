/* Measures the contrast of every visible piece of text against the real surface behind it (stacked backgrounds, opacity), and flags images
 * whose pixels would vanish on their backing. Runs inside the browser page; used by scripts/test-dark-mode-contrast.cjs. */
const probe = (root) => {
  const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[, /]+/).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const over = (top, bottom) => { const a = top.a + bottom.a * (1 - top.a); if (a === 0) return { r: 0, g: 0, b: 0, a: 0 }; return { r: (top.r * top.a + bottom.r * bottom.a * (1 - top.a)) / a, g: (top.g * top.a + bottom.g * bottom.a * (1 - top.a)) / a, b: (top.b * top.a + bottom.b * bottom.a * (1 - top.a)) / a, a }; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
  const hex = c => '#' + [c.r, c.g, c.b].map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
  const describe = el => { let s = el.tagName.toLowerCase(); if (el.id) s += '#' + el.id; const cl = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).slice(0, 3); if (cl.length) s += '.' + cl.join('.'); return s; };
  /* effective background: composite ancestors from the element up to the root over the page background (dark) */
  const backdrop = el => {
    let layers = []; let image = false;
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const cs = getComputedStyle(n);
      const bg = parse(cs.backgroundColor);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') image = true;
      if (bg && bg.a > 0) layers.push(bg);
      if (bg && bg.a >= 1) break;
    }
    let base = { r: 17, g: 26, b: 39, a: 1 }; // fallback = dark page colour
    const body = parse(getComputedStyle(document.body).backgroundColor); if (body && body.a > 0) base = { ...body, a: 1 };
    for (let i = layers.length - 1; i >= 0; i--) base = over(layers[i], base);
    return { color: base, image };
  };
  const opacityChain = el => { let o = 1; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const v = parseFloat(getComputedStyle(n).opacity); if (!isNaN(v)) o *= v; } return o; };
  const visible = el => { const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return false; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const cs = getComputedStyle(n); if (cs.display === 'none' || cs.visibility === 'hidden') return false; } return true; };

  const textFindings = []; const seen = new Set();
  const walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT);
  let node;
  while ((node = walker.nextNode())) {
    const txt = node.nodeValue.replace(/\s+/g, ' ').trim();
    if (txt.length < 2) continue;
    const el = node.parentElement;
    if (!el || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'OPTION'].includes(el.tagName) || !visible(el)) continue;
    if (el.closest('[disabled], [aria-disabled="true"]')) continue;   // disabled controls are exempt from the contrast rule
    const cs = getComputedStyle(el);
    let fg = parse(cs.color); if (!fg) continue;
    const op = opacityChain(el);
    const bd = backdrop(el);
    const fgEff = over({ ...fg, a: fg.a * op }, bd.color);
    const cr = ratio(fgEff, bd.color);
    const size = parseFloat(cs.fontSize); const bold = parseInt(cs.fontWeight, 10) >= 700;
    const large = size >= 24 || (size >= 18.66 && bold);
    const need = large ? 3 : 4.5;
    if (cr < need) {
      const key = describe(el) + '|' + hex(fgEff) + '|' + hex(bd.color);
      if (seen.has(key)) continue; seen.add(key);
      textFindings.push({ el: describe(el), parent: el.parentElement ? describe(el.parentElement) : '', text: txt.slice(0, 50), fg: hex(fgEff), bg: hex(bd.color), ratio: Math.round(cr * 100) / 100, need, opacity: Math.round(op * 100) / 100, bgImage: bd.image, fontSize: size });
    }
  }
  const imgFindings = [];
  for (const img of document.querySelectorAll('img')) {
    if (!visible(img)) continue;
    const r = img.getBoundingClientRect(); if (r.width < 12 || r.height < 12) continue;
    const bd = backdrop(img); const op = opacityChain(img); const cs = getComputedStyle(img);
    let stats = null;
    try {
      const c = document.createElement('canvas'); const w = (c.width = Math.min(64, img.naturalWidth || 64)); const h = (c.height = Math.min(64, img.naturalHeight || 64));
      const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, w, h);
      const d = ctx.getImageData(0, 0, w, h).data; let n = 0, tr = 0, sr = 0, sg = 0, sb = 0;
      for (let i = 0; i < d.length; i += 4) { if (d[i + 3] < 20) { tr++; continue; } n++; sr += d[i]; sg += d[i + 1]; sb += d[i + 2]; }
      const total = w * h; stats = { transparent: Math.round(100 * tr / total), avg: n ? { r: sr / n, g: sg / n, b: sb / n, a: 1 } : null };
    } catch (e) { stats = { error: 'unreadable' }; }
    const rec = { src: (img.getAttribute('src') || '').split('/').slice(-2).join('/').slice(0, 60), el: describe(img), bg: hex(bd.color), opacity: Math.round(op * 100) / 100, blend: cs.mixBlendMode, filter: cs.filter, transparentPct: stats && stats.transparent, size: Math.round(r.width) + 'x' + Math.round(r.height) };
    if (stats && stats.avg) { rec.avgColor = hex(stats.avg); rec.contrastWithBg = Math.round(ratio(stats.avg, bd.color) * 100) / 100; }
    const problem = (op < 0.85) || cs.mixBlendMode !== 'normal' || (rec.contrastWithBg !== undefined && rec.contrastWithBg < 2.2 && (stats.transparent > 5));
    if (problem) { rec.problem = true; imgFindings.push(rec); }
  }
  /* Inline SVG icons (stroke or fill) must reach 3:1 against what is behind them (WCAG 1.4.11, non-text contrast). */
  const iconFindings = []; const seenIcon = new Set();
  for (const svg of document.querySelectorAll('svg')) {
    if (!visible(svg)) continue;
    const rect = svg.getBoundingClientRect(); if (rect.width < 10 || rect.height < 10) continue;
    if (svg.closest('[disabled], [aria-disabled="true"]')) continue;
    let color = null;
    for (const sh of svg.querySelectorAll('path, circle, rect, line, polyline, polygon, ellipse')) {
      const cs = getComputedStyle(sh);
      if (cs.stroke && cs.stroke !== 'none') { const c = parse(cs.stroke); if (c) { c.a *= parseFloat(cs.strokeOpacity || 1); color = c; break; } }
      if (cs.fill && cs.fill !== 'none') { const c = parse(cs.fill); if (c && c.a > 0) { c.a *= parseFloat(cs.fillOpacity || 1); color = c; break; } }
    }
    if (!color) color = parse(getComputedStyle(svg).color);
    if (!color) continue;
    const bd = backdrop(svg); const op = opacityChain(svg);
    const fgEff = over({ r: color.r, g: color.g, b: color.b, a: color.a * op }, bd.color);
    const cr = ratio(fgEff, bd.color);
    if (cr < 3) {
      const key = describe(svg.parentElement || svg) + '|' + hex(fgEff) + '|' + hex(bd.color);
      if (seenIcon.has(key)) continue; seenIcon.add(key);
      iconFindings.push({ el: describe(svg), parent: svg.parentElement ? describe(svg.parentElement) : '', fg: hex(fgEff), bg: hex(bd.color), ratio: Math.round(cr * 100) / 100, size: Math.round(rect.width) + 'x' + Math.round(rect.height) });
    }
  }
  return { textFindings, imgFindings, iconFindings, theme: document.documentElement.getAttribute('data-theme') };
};
module.exports = { probe };
