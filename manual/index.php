<?php
/**
 * MyInvoice.cz — HTML manuál (route handler).
 *
 * URL: /manual                    → INDEX.html (rozcestník)
 * URL: /manual?ch=01_Uvod         → kapitola
 * URL: /manual?ch=01_Uvod#1.2     → kapitola se skokem na sekci
 *
 * Bez auth (manuál je veřejný — není v něm citlivý obsah; pokud chceš
 * auth-gate, doplň session check níže).
 *
 * Vyžaduje vygenerovaný obsah v manual/generated/ (php tools/generateManualHtml.php).
 */

declare(strict_types=1);

$dir   = __DIR__ . '/generated';
$tocFile = $dir . '/_toc.php';
$ch    = isset($_GET['ch']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['ch']) : '';

if (!is_file($tocFile)) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Manuál</title>';
    echo '<h1>Manuál není zatím vygenerovaný.</h1>';
    echo '<p>Spusť: <code>php tools/generateManualHtml.php</code></p>';
    exit;
}

$groups = require $tocFile;

// Resolve aktuální kapitolu
$bodyHtml = '';
$activeFile = '';
$activeTitle = 'MyInvoice.cz — manuál';

if ($ch !== '') {
    $f = $dir . '/' . $ch . '.html';
    if (is_file($f)) {
        $bodyHtml = file_get_contents($f);
        $activeFile = $ch;
        // Najdi titulek z _toc
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                if ($it['file'] === $ch) { $activeTitle = $it['title'] . ' — manuál'; break 2; }
            }
        }
    }
}

if ($bodyHtml === '') {
    // Default landing
    $indexFile = $dir . '/INDEX.html';
    $bodyHtml = is_file($indexFile) ? file_get_contents($indexFile) : '<h1>Manuál</h1>';
    $activeFile = 'INDEX';
}

?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($activeTitle, ENT_QUOTES) ?></title>
    <link rel="icon" type="image/svg+xml" href="/styles/logo.svg">
    <style>
:root {
    --primary: #6c5ce7;
    --primary-dark: #4f3fb8;
    --primary-light: #ede9fe;
    --bg: #fafbff;
    --panel: #fff;
    --border: #e5e7eb;
    --text: #1f2937;
    --muted: #6b7280;
    --code-bg: #f3f4f6;
    --code-text: #c0392b;
    /* Sidebar — tmavší tón pro vizuální oddělení od obsahu */
    --sidebar-bg: #1e1b3a;
    --sidebar-bg-2: #15132b;
    --sidebar-text: #e5e3f5;
    --sidebar-muted: #9b96c0;
    --sidebar-border: rgba(255,255,255,0.08);
    --sidebar-hover: rgba(108,92,231,0.18);
    --sidebar-active: #6c5ce7;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", sans-serif;
    font-size: 16px;
    line-height: 1.6;
    color: var(--text);
    background: var(--bg);
}
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
.layout { display: grid; grid-template-columns: 280px minmax(0, 1fr); min-height: 100vh; }
html, body { overflow-x: hidden; max-width: 100%; }
.sidebar {
    background: linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
    color: var(--sidebar-text);
    border-right: 1px solid var(--sidebar-border);
    padding: 24px 0;
    overflow-y: auto;
    position: sticky;
    top: 0;
    height: 100vh;
    box-shadow: 2px 0 16px rgba(0,0,0,0.08);
}
.sidebar-brand {
    padding: 0 20px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.sidebar-brand img {
    width: 36px;
    height: 36px;
    background: var(--panel);
    border-radius: 8px;
    padding: 4px;
}
.sidebar-brand .title { font-weight: 700; color: #fff; font-size: 16px; }
.sidebar-brand .sub { font-size: 12px; color: var(--sidebar-muted); margin-top: 2px; }
.sidebar-brand a { color: inherit; text-decoration: none; }
.toc-group { margin: 0 0 24px; }
.toc-group-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #fff;
    padding: 12px 20px 8px;
    font-weight: 800;
    background: rgba(255,255,255,0.04);
    margin-bottom: 6px;
    position: relative;
    border-bottom: 2px solid #f5d142;
}
.toc-group-title::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--primary);
}
.toc-group ul { list-style: none; margin: 0; padding: 0; }
.toc-group li a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 20px 8px 24px;
    font-size: 14px;
    color: var(--sidebar-text);
    border-left: 3px solid transparent;
    transition: all 0.15s;
}
.toc-group li a::before {
    content: "›";
    color: #f5d142;
    font-size: 16px;
    font-weight: 800;
    line-height: 1;
    flex: 0 0 auto;
    opacity: 0.7;
}
.toc-group li a:hover {
    background: var(--sidebar-hover);
    border-left-color: var(--primary);
    color: #fff;
    text-decoration: none;
}
.toc-group li a:hover::before { opacity: 1; }
.toc-group li a.active {
    background: var(--sidebar-active);
    border-left-color: #f5d142;
    font-weight: 600;
    color: #fff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
}
.toc-group li a.active::before { color: #fff; opacity: 1; content: "•"; }
.toc-group li.sub a {
    padding-left: 44px;
    font-size: 13px;
    color: var(--sidebar-muted);
}
.toc-group li.sub a::before { content: "—"; color: var(--sidebar-muted); font-size: 13px; }
.toc-group li.sub a:hover { color: #fff; background: rgba(255,255,255,0.04); border-left-color: transparent; }
.toc-group li.sub a:hover::before { color: #fff; }
.search-wrap { padding: 0 20px 20px; }
.search-wrap input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--sidebar-border);
    background: rgba(255,255,255,0.06);
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    color: #fff;
}
.search-wrap input::placeholder { color: var(--sidebar-muted); }
.search-wrap input:focus {
    border-color: var(--primary);
    background: rgba(255,255,255,0.1);
    box-shadow: 0 0 0 3px rgba(108,92,231,0.2);
}
.search-results {
    margin-top: 8px;
    max-height: 300px;
    overflow-y: auto;
    background: var(--panel);
    border-radius: 8px;
    display: none;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
}
.search-results.active { display: block; }
.search-results .result {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    font-size: 13px;
    color: var(--text);
}
.search-results .result:hover { background: var(--bg); }
.search-results .result-title { font-weight: 600; color: var(--primary-dark); }
.search-results .result-snip { color: var(--muted); font-size: 12px; margin-top: 2px; }
.sidebar-footer {
    padding: 16px 20px;
    font-size: 12px;
    color: var(--sidebar-muted);
    text-align: center;
    border-top: 1px solid var(--sidebar-border);
    margin-top: 12px;
}
.sidebar-footer a { color: var(--primary); }
.sidebar-footer a:hover { color: #fff; }
.btn-back {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0 20px 16px;
    padding: 10px 14px;
    background: rgba(245,209,66,0.12);
    border: 1px solid rgba(245,209,66,0.35);
    color: #f5d142;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}
.btn-back:hover {
    background: #f5d142;
    color: var(--sidebar-bg);
    text-decoration: none;
    transform: translateX(-2px);
}
.btn-back svg { width: 16px; height: 16px; }
.sidebar-version {
    display: inline-block;
    background: var(--primary);
    color: #fff;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 4px;
}
.content {
    /* Content fills full main column (po sidebaru). Žádný max-width, aby
       text + figure margin auto vycentrovaly v celé dostupné šířce — bez
       dead-space napravo od contentu. */
    padding: 32px 40px;
    width: 100%;
    min-width: 0;
    /* Long URL nebo unbreakable strings nemají rozbít grid column */
    overflow-wrap: break-word;
    word-wrap: break-word;
    /* Optional: na ultra-wide (>1600px) limitovat čitelnost textu (max-width
       na <p>, <li> atd.) — figure ne. Viz pravidla níž. */
}
/* Limit šířky čitelného textu na ultra-wide displays (čitelnost: ~80 chars/line) */
.content > p,
.content > ul,
.content > ol,
.content > blockquote,
.content > h1,
.content > h2,
.content > h3,
.content > table {
    max-width: 1100px;
    margin-left: auto;
    margin-right: auto;
}
.content pre.code-block { max-width: 100%; }
.content table.md-tab { table-layout: fixed; }
.content h1 { font-size: 32px; line-height: 1.2; margin: 0 0 24px; color: var(--text); }
.content h2 { font-size: 24px; line-height: 1.3; margin: 32px 0 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border); color: var(--text); }
.content h3 { font-size: 18px; margin: 24px 0 12px; color: var(--text); }
.content p { margin: 0 0 14px; }
.content ul, .content ol { margin: 0 0 14px; padding-left: 28px; }
.content li { margin-bottom: 4px; }
.content code {
    background: var(--code-bg);
    color: var(--code-text);
    padding: 1px 5px;
    border-radius: 3px;
    font-family: "JetBrains Mono", "Fira Code", Consolas, monospace;
    font-size: 13px;
}
.content pre.code-block {
    background: #1e1e2e;
    color: #cdd6f4;
    padding: 16px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 0 0 16px;
    font-family: "JetBrains Mono", Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
}
.content pre.code-block code { background: transparent; color: inherit; padding: 0; }
.content blockquote {
    margin: 0 0 16px;
    padding: 12px 16px;
    background: #fff8e1;
    border-left: 4px solid #f39c12;
    border-radius: 0 6px 6px 0;
}
.content blockquote p { margin: 0; }
.content table.md-tab {
    border-collapse: collapse;
    width: 100%;
    margin: 0 0 16px;
    font-size: 14px;
}
.content table.md-tab th,
.content table.md-tab td {
    border: 1px solid var(--border);
    padding: 8px 12px;
}
.content table.md-tab th {
    background: var(--bg);
    font-weight: 600;
}
.content table.md-tab tr:nth-child(2n) td { background: #fafafa; }
.content figure.fig {
    /* `fit-content` zmenší figure na šířku obrázku — malé natural, velké capped na 100%.
       Žádný border / pozadí — jen samotný obrázek, vystředěný. */
    width: fit-content;
    max-width: 100%;
    margin: 16px auto;
    padding: 0;
}
.content figure.fig img {
    /* Velké obrázky (> šířka contentu) zmenší na 100%; malé zůstanou v natural size.
       `margin: 0 auto` vycentruje img v figure — důležité, když JS pak nastaví img
       max-width pro DPR scaling (img je užší než figure-natural-width). */
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.content figure.fig figcaption {
    text-align: center;
    margin-top: 6px;
    font-size: 13px;
    color: var(--muted);
    font-style: italic;
}
.content figure.fig figcaption {
    margin-top: 8px;
    font-size: 13px;
    color: var(--muted);
    font-style: italic;
}
.content hr { border: 0; border-top: 1px solid var(--border); margin: 32px 0; }
@media (max-width: 768px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar { position: static; height: auto; }
    .content { padding: 20px; }
}
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="/styles/logo.svg" alt="logo">
            <div>
                <div class="title"><a href="/manual">MyInvoice.cz</a></div>
                <div class="sub">Manuál <span class="sidebar-version">v1.0</span></div>
            </div>
        </div>
        <a href="/" class="btn-back">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
            Zpět do Admin
        </a>
        <div class="search-wrap">
            <input type="search" id="manual-search" placeholder="Hledat v manuálu…" />
            <div class="search-results" id="search-results"></div>
        </div>
        <?php foreach ($groups as $g): ?>
            <div class="toc-group">
                <div class="toc-group-title"><?= htmlspecialchars($g['title']) ?></div>
                <ul>
                <?php foreach ($g['items'] as $it): ?>
                    <li>
                        <a href="/manual?ch=<?= urlencode($it['file']) ?>"
                           class="<?= $activeFile === $it['file'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($it['title']) ?>
                        </a>
                    </li>
                    <?php if ($activeFile === $it['file'] && !empty($it['sub'])): ?>
                        <?php foreach ($it['sub'] as $s): ?>
                            <li class="sub"><a href="#<?= htmlspecialchars($s['slug']) ?>"><?= htmlspecialchars($s['text']) ?></a></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <div class="sidebar-footer">
            Vyvíjí <a href="https://mywebdesign.cz/" target="_blank">MyWebdesign.cz</a>
        </div>
    </aside>
    <main class="content">
        <?= $bodyHtml ?>
    </main>
</div>

<script>
// Image DPR scaling — Windows scaling 125% způsobuje, že screenshot 880px se
// vykreslí na 1100 device px (browser upscaluje pro vysoké DPI desktop displeje).
// Image omezíme na natural / dpr, takže 1 source px = 1 device px (1:1 mapping).
//
// Mobile (typicky dpr 2–3) tohle PŘESKAKUJE — tam by se obrázek zmenšil na 1/3
// a uživatel by neviděl detaily. Místo toho jen max-width: 100% (responsive).
//
// Trigger: úzké viewporty (< 1024px) → bez scalingu, jen 100% width.
(function() {
  const dpr = window.devicePixelRatio || 1;
  if (dpr <= 1) return;
  const apply = (img) => {
    if (img.naturalWidth <= 0) return;
    if (window.innerWidth < 1024) {
      // Mobile / tablet — ponech responsive (default max-width: 100%)
      img.style.maxWidth = '100%';
      return;
    }
    const px = Math.round(img.naturalWidth / dpr);
    img.style.maxWidth = `min(${px}px, 100%)`;
  };
  const all = () => document.querySelectorAll('.content figure.fig img').forEach(img => {
    if (img.complete) apply(img); else img.addEventListener('load', () => apply(img));
  });
  all();
  // Re-aplikuj při resize (přechod desktop ↔ mobile)
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(all, 150);
  });
})();

// Klientské vyhledávání přes search-index.json
(async function() {
    const input = document.getElementById('manual-search');
    const results = document.getElementById('search-results');
    if (!input) return;
    let index = null;
    async function loadIndex() {
        if (index) return index;
        const r = await fetch('/manual/generated/search-index.json');
        index = await r.json();
        return index;
    }
    function debounce(fn, ms) {
        let t;
        return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
    }
    function score(item, query) {
        const q = query.toLowerCase();
        let s = 0;
        if (item.t.toLowerCase().includes(q)) s += 100;
        for (const sec of item.s) if (sec.t.toLowerCase().includes(q)) s += 50;
        if (item.b.toLowerCase().includes(q)) s += 10;
        return s;
    }
    function snippet(text, query) {
        const q = query.toLowerCase();
        const i = text.toLowerCase().indexOf(q);
        if (i < 0) return text.substring(0, 80) + '…';
        const from = Math.max(0, i - 30);
        const to = Math.min(text.length, i + q.length + 50);
        return (from > 0 ? '…' : '') + text.substring(from, to) + (to < text.length ? '…' : '');
    }
    input.addEventListener('input', debounce(async function() {
        const q = input.value.trim();
        if (q.length < 2) { results.classList.remove('active'); results.innerHTML = ''; return; }
        const idx = await loadIndex();
        const matches = idx.map(it => ({ item: it, score: score(it, q) })).filter(x => x.score > 0).sort((a, b) => b.score - a.score).slice(0, 8);
        if (!matches.length) { results.classList.add('active'); results.innerHTML = '<div class="result">Nic nenalezeno.</div>'; return; }
        results.innerHTML = matches.map(m => {
            const url = '/manual?ch=' + encodeURIComponent(m.item.f);
            return '<div class="result" onclick="location.href=\'' + url + '\'"><div class="result-title">' + m.item.t + '</div><div class="result-snip">' + snippet(m.item.b, q).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</div></div>';
        }).join('');
        results.classList.add('active');
    }, 200));
    input.addEventListener('blur', () => setTimeout(() => results.classList.remove('active'), 200));
})();
</script>
</body>
</html>
