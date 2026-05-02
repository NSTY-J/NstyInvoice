# MyInvoice.cz — Design system

## 1. Filozofie

Lehký, přehledný, finančně-důvěryhodný. Inspirace: Stripe Dashboard, Lemon Squeezy, Linear (typografie). Žádné skleněné efekty, žádné gradienty napříč pozadím. Funkční minimalismus s jednou výraznou barvou pro „akční" stavy.

**Pravidla:**
- Bílé pozadí, jemné šedé hranice, **emerald** jako jediná akční barva
- Primární akce má vždy plný button, sekundární outline
- Tabulky jsou hlavní UI primitive (faktury jsou data)
- Žádné modální okna pro editaci dat — celostránkový editor
- Mobile-friendly, ale nepřioptimalizováno (cílový user je u stolu)

## 2. Paleta

```css
@theme {
  /* Primary - emerald */
  --color-primary-50:  #ECFDF5;
  --color-primary-100: #D1FAE5;
  --color-primary-200: #A7F3D0;
  --color-primary-300: #6EE7B7;
  --color-primary-400: #34D399;
  --color-primary-500: #10B981;
  --color-primary-600: #059669;   /* MAIN */
  --color-primary-700: #047857;
  --color-primary-800: #065F46;
  --color-primary-900: #064E3B;

  /* Neutral - zinc */
  --color-neutral-50:  #FAFAFA;
  --color-neutral-100: #F4F4F5;
  --color-neutral-200: #E4E4E7;
  --color-neutral-300: #D4D4D8;
  --color-neutral-400: #A1A1AA;
  --color-neutral-500: #71717A;
  --color-neutral-600: #52525B;
  --color-neutral-700: #3F3F46;
  --color-neutral-800: #27272A;
  --color-neutral-900: #18181B;   /* TEXT */

  /* Accent - blue (info, links) */
  --color-accent-500:  #3B82F6;
  --color-accent-600:  #2563EB;

  /* Semantic */
  --color-success-500: #10B981;
  --color-warning-500: #F59E0B;
  --color-warning-50:  #FFFBEB;
  --color-danger-500:  #DC2626;
  --color-danger-50:   #FEF2F2;
  /* Surfaces */
  --color-bg:          #FBFAFD;
  --color-surface:     #FFFFFF;
  --color-border:      #E7E3EE;
  --color-border-strong: #D2CCDF;

  /* Text */
  --color-text:         #15131D;
  --color-text-muted:   #7A748C;
  --color-text-subtle:  #A7A0BA;

  /* Status badges (faktury) — pastelové */
  --color-status-draft-bg:     #F4F2F8;
  --color-status-draft-fg:     #5A5470;
  --color-status-issued-bg:    #E5E0F4;
  --color-status-issued-fg:    #3B2D83;
  --color-status-sent-bg:      #D7E8F6;
  --color-status-sent-fg:      #1F5E97;
  --color-status-paid-bg:      #ECF6F0;
  --color-status-paid-fg:      #2E7B53;
  --color-status-overdue-bg:   #FBEDED;
  --color-status-overdue-fg:   #B84545;
  --color-status-cancelled-bg: #F4F2F8;
  --color-status-cancelled-fg: #A7A0BA;
}
```

## 3. Typografie

| Použití | Font | Weight | Size |
|---|---|---|---|
| UI text | **Inter** | 400 | 14px |
| Nadpisy | Inter | 600/700 | 18-32px |
| Čísla, var. symboly, IBAN | **Geist Mono** | 500 | 14px |
| Faktura PDF body | DejaVu Sans | 400 | 10pt |
| Faktura PDF nadpisy | DejaVu Sans | 700 | 12-16pt |

```css
@font-face { font-family: 'Inter'; src: url('/fonts/Inter-var.woff2') format('woff2-variations'); font-weight: 100 900; font-display: swap; }
@font-face { font-family: 'Geist Mono'; src: url('/fonts/GeistMono-var.woff2') format('woff2-variations'); font-weight: 100 900; font-display: swap; }
```

### Type scale
- `text-xs`  : 12px / 16px
- `text-sm`  : 14px / 20px  ← UI default
- `text-base`: 16px / 24px
- `text-lg`  : 18px / 28px
- `text-xl`  : 20px / 28px
- `text-2xl` : 24px / 32px  ← page heading
- `text-3xl` : 30px / 36px

## 4. Spacing & layout

- Base spacing: 4px grid
- App max width: 1440px
- Sidebar width: 240px (sbalená 64px)
- Page padding: 24px (mobile 16px)
- Component padding (card): 20px
- Border-radius: **6px** (sm), **8px** (md, default), **12px** (lg, modaly)
- Shadow scale (jen tři):
  - `shadow-sm`: `0 1px 2px rgb(0 0 0 / 0.04)`
  - `shadow-md`: `0 4px 12px rgb(0 0 0 / 0.06), 0 1px 3px rgb(0 0 0 / 0.04)`
  - `shadow-lg`: `0 12px 32px rgb(0 0 0 / 0.10), 0 4px 8px rgb(0 0 0 / 0.04)`

## 5. Komponenty

### Button
```
.btn-primary   bg-primary-600 text-white hover:bg-primary-700 active:bg-primary-800
.btn-secondary bg-white text-neutral-900 border border-neutral-300 hover:bg-neutral-50
.btn-danger    bg-danger-500 text-white hover:bg-danger-600
.btn-ghost     text-neutral-700 hover:bg-neutral-100
```
- Sizes: `sm` (32px), `md` (40px, default), `lg` (44px)
- Vždy `font-medium`, `radius=6px`
- Icon button: square, 40x40

### Input
- Height 40px, border `neutral-300`, radius 6px
- Focus: ring 2px `primary-500/20` + border `primary-500`
- Error: border `danger-500`, helper text `danger-600`
- Label nad inputem, `font-medium text-sm text-neutral-700`

### Table
- Header `bg-neutral-50`, `text-neutral-500`, `text-xs font-medium uppercase tracking-wide`
- Rows: hover `bg-neutral-50`, divide-y `divide-neutral-200`
- Padding: `px-4 py-3`
- Sticky header při scrollu

### Status badge
```html
<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
             bg-status-paid-bg text-status-paid-fg">Zaplaceno</span>
```

### Card
- `bg-surface border border-border rounded-lg shadow-sm`
- Header: `px-5 py-4 border-b border-border` se titulkem `text-base font-semibold`
- Body: `p-5`

### Modal (jen pro akce, ne edit data)
- Overlay: `bg-neutral-900/40 backdrop-blur-sm`
- Panel: `bg-surface rounded-xl shadow-lg`, max-width 480px
- ESC zavírá, klik na overlay zavírá

## 6. Layout

```
┌──────────────────────────────────────────────────────┐
│  TopBar (64px) — logo, search, user menu            │
├────────┬─────────────────────────────────────────────┤
│        │                                             │
│  Side  │                                             │
│  bar   │  Page content (max 1200px, padding 24px)   │
│  240   │                                             │
│  px    │                                             │
│        │                                             │
└────────┴─────────────────────────────────────────────┘
```

**Sidebar items:**
- Dashboard
- Faktury
- Klienti
- Zakázky
- Dodavatel (settings)
- Aktivita (admin)
- Šablony emailů (admin)
- Uživatelé (admin)

## 7. Klíčové obrazovky (wireframes textově)

### Login
```
            ┌──────────────────┐
            │   [logo]  MyInvoice
            │
            │   Přihlášení
            │
            │   Email   [______________]
            │   Heslo   [______________]
            │
            │   [ Přihlásit ]
            │
            │   Zapomenuté heslo?
            └──────────────────┘
```
Středěno, max-width 360px, surface card.

### Dashboard
- 4× KPI tiles: Letošní obrat (CZK), Letošní obrat (EUR), Po splatnosti (počet, suma), Vystaveno tento měsíc
- Sekce „Po splatnosti" (tabulka, top 5)
- Sekce „Posledních 10 faktur"
- Sekce „Aktivní zakázky" (top 5 podle obratu YTD)

### Invoice editor (klíčová obrazovka)
```
[← Zpět]   Faktura č. 2026040001 [DRAFT]    [Klonovat] [Smazat] [Vystavit]

┌─Klient & zakázka─────────┐ ┌─Datumy────────────────┐
│ Klient:  ACME s.r.o.     │ │ Vystaveno: 2026-04-30 │
│ Zakázka: Údržba 2026     │ │ DUZP:      2026-04-30 │
│ Měna:    CZK             │ │ Splatnost: 2026-05-07 │
│ Reverse: ne              │ │                       │
└──────────────────────────┘ └───────────────────────┘

┌─Položky─────────────────────────────────────────────┐
│ # │ Popis              │ Mn. │ J. │ Cena/j │ DPH │ Celkem │
│ 1 │ Konzultace 4/2026  │ 10  │ h  │  1 500 │ 21% │ 18 150 │
│ 2 │ Vícepráce 4/2026 ⓘ │  1  │ ks │  9 000 │ 21% │ 10 890 │
│       [+ Přidat položku]                                  │
└─────────────────────────────────────────────────────┘

┌─Výkaz víceprací (volitelný) ────────────────────────┐
│ Název: [Vícepráce za měsíc 4/2026__________]        │
│ Zakázka: Údržba 2026                                │
│ ─────────────────────────────────────────────────── │
│ # │ Popis            │ Hodin │ Sazba │ Celkem      │
│ 1 │ Refaktor login   │  4.5  │ 1 500 │  6 750      │
│ 2 │ Bugfix QR        │  1.0  │ 1 500 │  1 500      │
│   [+ Přidat]                          Σ    9 000   │
│   ☑ Aktualizovat položku faktury                   │
└─────────────────────────────────────────────────────┘

                                Bez DPH:    24 000
                                DPH (21%):   5 040
                                ─────────────────────
                                Celkem CZK:  29 040
```

### Invoice list
Sticky header tabulky, filter bar nahoře (status pillsy + datumové rozsahy + klient autocomplete), CSV export tlačítko vpravo.

## 8. Logo (SVG)

Monogram **M** v emerald boxu se zeleným checkmarkem v horním rohu. Funguje 16×16 (favicon) i 64×64 (sidebar) i 200×200 (faktura).

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="MyInvoice">
  <defs>
    <linearGradient id="mi-bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#10B981"/>
      <stop offset="1" stop-color="#059669"/>
    </linearGradient>
  </defs>
  <rect x="2" y="2" width="60" height="60" rx="12" fill="url(#mi-bg)"/>
  <path d="M14 46 V18 L24 18 L32 34 L40 18 L50 18 V46 H43 V28 L34 44 L30 44 L21 28 V46 Z"
        fill="#FFFFFF"/>
  <circle cx="50" cy="14" r="9" fill="#FFFFFF"/>
  <path d="M46 14 L49 17 L54 11" fill="none" stroke="#059669" stroke-width="2.5"
        stroke-linecap="round" stroke-linejoin="round"/>
</svg>
```

### Wordmark (horizontální verze pro top bar a faktury)

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 64" role="img" aria-label="MyInvoice.cz">
  <defs>
    <linearGradient id="mi-bg2" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#10B981"/>
      <stop offset="1" stop-color="#059669"/>
    </linearGradient>
  </defs>
  <rect x="4" y="4" width="56" height="56" rx="10" fill="url(#mi-bg2)"/>
  <path d="M14 46 V18 L24 18 L32 34 L40 18 L50 18 V46 H43 V28 L34 44 L30 44 L21 28 V46 Z" fill="#FFFFFF"/>
  <circle cx="50" cy="14" r="8" fill="#FFFFFF"/>
  <path d="M46.5 14 L49 16.5 L53.5 11.5" fill="none" stroke="#059669" stroke-width="2.2"
        stroke-linecap="round" stroke-linejoin="round"/>
  <text x="76" y="42" font-family="Inter, system-ui, sans-serif" font-weight="700" font-size="28" fill="#18181B">My<tspan fill="#059669">Invoice</tspan></text>
  <text x="76" y="56" font-family="Inter, system-ui, sans-serif" font-weight="500" font-size="11" fill="#71717A" letter-spacing="2">FAKTURY · QR · ARES</text>
</svg>
```

> Oba SVG už existují v `/styles/logo.svg` (mark) a `/styles/logo-wordmark.svg`. Web servíruje `/styles/` jako statický asset; SPA i PDF generátor je odtud načítají. Na PDF faktuře se použije wordmark v barvě jen tam, kde tisk dává smysl, jinak monochromatický fallback.

### Favicon
- `/styles/logo.svg` použitý přímo jako favicon (`<link rel="icon" href="/styles/logo.svg" type="image/svg+xml">`)

## 9. PDF styly (mPDF)

mPDF má omezenější CSS support — žádné CSS Grid, omezený Flexbox. Používáme `<table>` layout (jako v `Faktura::renderIt()`).

### Klíčové styly
```css
@page { size: A4; margin: 15mm 15mm 20mm 15mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #18181B; line-height: 1.4; }
h1 { font-size: 18pt; font-weight: 700; color: #059669; margin: 0 0 4pt 0; }
h2 { font-size: 12pt; font-weight: 700; color: #27272A; margin: 8pt 0 4pt 0; }
.muted { color: #71717A; font-size: 9pt; }
.right { text-align: right; }
table.full { width: 100%; border-collapse: collapse; }
table.items th { background: #F4F4F5; color: #52525B; font-size: 9pt; text-transform: uppercase;
                 letter-spacing: 0.5pt; padding: 6pt; border-bottom: 1pt solid #D4D4D8; }
table.items td { padding: 6pt; border-bottom: 0.5pt solid #E4E4E7; vertical-align: top; }
.totals td { padding: 4pt 8pt; }
.totals .grand { font-size: 14pt; font-weight: 700; border-top: 1pt solid #18181B; }
.qr-box { display: inline-block; padding: 6pt; border: 1pt solid #D4D4D8; border-radius: 4pt;
          background: #FFFFFF; text-align: center; }
.rc-note { background: #FFFBEB; border: 1pt solid #F59E0B; padding: 8pt; border-radius: 4pt;
           color: #92400E; font-size: 9pt; margin: 8pt 0; }
```

## 10. Ikony

**Heroicons v2** (outline 24px pro UI, solid 20px pro buttons). Konzistentní s Tailwind ekosystémem, MIT licence.

Per akce:
- Faktury: `document-text`
- Klienti: `users`
- Zakázky: `briefcase`
- Dodavatel: `building-office-2`
- PDF: `arrow-down-tray`
- Email: `paper-airplane`
- Klonovat: `document-duplicate`
- Vystavit: `check-badge`
- Storno: `x-circle`
- Zaplaceno: `check-circle`

## 11. Loading & empty states

- Loading: skeleton boxy (ne spinner) v tabulkách a kartách
- Empty: ilustrace 120×120 (jednoduchý SVG) + text + primární CTA
- Error: červená banner nahoře + retry button

## 12. Toasty

Pravý dolní roh, `shadow-lg`, auto-dismiss 4s pro success, 8s pro error, manual pro warning.
- success: emerald-600 ikona, neutrální text
- error: danger-500 ikona
- warning: warning-500 ikona

## 13. Dark mode

**Out of scope pro M0-M3.** Po stabilizaci zvážit (s `data-theme="dark"` a CSS proměnnými už je infrastruktura připravená).

## 14. Akcessibilita

- Vždy `<label>` k inputům (může být sr-only, ale být)
- Focus ring viditelný (Tailwind `focus-visible:ring-2 focus-visible:ring-primary-500`)
- Kontrast min 4.5:1 (paleta to splňuje)
- Tabulky: `<th scope="col">`
- Modal: focus trap, ARIA `role=dialog aria-modal=true`
