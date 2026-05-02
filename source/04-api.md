# MyInvoice.cz — REST API specifikace

> Base URL: `https://myinvoice.cz/api` (prod), `https://dev.myinvoice.cz/api` (dev), `http://localhost:8080/api` (lokál).
> Verze v URL **není** — jeden konzument (vlastní SPA), breaking changes řeší deploy.
> Formát: JSON, charset UTF-8, datum ISO 8601 (`YYYY-MM-DD`), timestamp `YYYY-MM-DD HH:MM:SS` (lokální čas Europe/Prague).
> Auth: session cookie + `X-CSRF-Token` header pro mutace.

## Stav implementace (2026-05-01)

Reálné endpointy v `api/src/Routes.php`. Zdrojem pravdy je `Routes.php`, tento dokument je
historická specifikace, která se v některých detailech (cesty, query params) liší.

**Hlavní implementované cesty:**

```
# Auth
POST /api/auth/login           POST /api/auth/logout
GET  /api/auth/me              GET  /api/auth/setup-status
POST /api/auth/setup           POST /api/auth/forgot
POST /api/auth/reset           POST /api/auth/change-password

# Klienti / zakázky
GET/POST/PUT/DELETE  /api/clients[/{id}]      + /archive, /unarchive, /lookup-ares, /lookup-vies
GET/POST/PUT/DELETE  /api/projects[/{id}]     + /archive

# Faktury
GET/POST/PUT/DELETE  /api/invoices[/{id}]
POST /api/invoices/{id}/issue            POST /api/invoices/{id}/mark-paid
POST /api/invoices/{id}/cancel           POST /api/invoices/{id}/clone
POST /api/invoices/{id}/issue-final      POST /api/invoices/bulk-reissue
POST /api/invoices/{id}/send             POST /api/invoices/{id}/send-test
GET  /api/invoices/{id}/pdf              # PDF download / náhled
GET/PUT/DELETE  /api/invoices/{id}/work-report

# Banka (M5b)
POST /api/bank-statements/upload
GET  /api/bank-statements                GET /api/bank-statements/{id}
POST /api/bank-transactions/{id}/match   POST /api/bank-transactions/{id}/ignore

# Dashboard
GET  /api/dashboard/summary

# Číselníky a settings (admin)
GET/PUT  /api/settings/supplier
GET/POST/PUT/DELETE  /api/settings/currencies[/{code}]
GET/POST/PUT/DELETE  /api/settings/vat-rates[/{id}]
GET/POST/PUT/DELETE  /api/settings/countries[/{id}]
GET  /api/codebooks/{type}    # vat-rates|currencies|countries (read-only, pro editor)

# Admin (admin only)
GET  /api/admin/activity-log
GET  /api/admin/invoices-zip?month=YYYY-MM[&type=]   # ZIP export PDF za měsíc
GET/POST/PUT/DELETE  /api/admin/users[/{id}]
```

**Klíčové rozdíly oproti původní specifikaci:**
- Edit vystavené faktury: `PUT /api/invoices/{id}?force=1` (admin, audit log `invoice.force_updated`)
- Settings sloučené pod `/api/settings/*` (původně bylo `/supplier`, `/admin/security`, …)
- Bank import nebyl v původní spec — přibyl jako M5b
- ZIP export přibyl v M6
- IP allowlist a security policies se konfigurují přes `cfg.php`, ne přes API

---

## Obecné konvence

### HTTP statusy
| Kód | Kdy |
|---|---|
| 200 | OK (GET, úspěšný PUT) |
| 201 | Created (POST) |
| 204 | No content (DELETE) |
| 400 | Validace selhala |
| 401 | Není přihlášený |
| 403 | Přihlášený, ale chybí oprávnění |
| 404 | Nenalezeno |
| 409 | Konflikt (např. faktura už issued, nelze editovat) |
| 422 | Sémantická chyba (např. záporné množství) |
| 429 | Rate limit |
| 500 | Server error |

### Error response
```json
{
  "error": {
    "code": "validation_failed",
    "message": "Validace selhala",
    "fields": {
      "main_email": ["Email je povinný"],
      "ic": ["IČ musí mít 8 číslic"]
    }
  }
}
```

### Paginated list
```json
{
  "data": [ ... ],
  "meta": { "total": 142, "page": 1, "per_page": 20, "pages": 8 }
}
```

### Query parameters pro listy
- `page` (default 1)
- `per_page` (default 20, max 100)
- `sort` (např. `-issue_date,client_name`)
- `q` (full-text search, kde dává smysl)
- `filter[<field>]` (např. `filter[status]=issued&filter[client_id]=42`)

---

## 1. Auth

### GET `/auth/setup-status`
Always-available endpoint. Vrací stav prvotního nastavení + public captcha info.
Response 200:
```json
{
  "needs_setup": true,
  "version": "0.1.0",
  "captcha": { "provider": "turnstile", "site_key": "0x4...", "script_url": "https://..." }
}
```
Pokud `needs_setup=true`, frontend přesměruje na `/setup` wizard. Všechny ostatní endpointy (kromě `/health`, `/auth/setup`) vrací 423 *Locked*.

### POST `/auth/setup`
First-run admin setup. Funguje **jen pokud `users` je prázdná**.
Body:
```json
{
  "admin": {
    "name": "Jan Novák",
    "email": "admin@example.com",
    "password": "min-12-chars-password"
  },
  "supplier": {                    // volitelné — pokud null, dodavatel se nastaví později
    "company_name": "Novák s.r.o.",
    "ic": "12345678",
    "dic": "CZ12345678",
    "street": "...", "city": "...", "zip": "...", "country_iso2": "CZ",
    "email": "billing@example.com",
    "default_currency": "CZK",
    "bank_account": {              // volitelný první účet
      "currency": "CZK",
      "account_number": "1000000005",
      "bank_code": "0100",
      "bank_name": "Komerční banka"
    }
  }
}
```
Response 201:
```json
{ "user": { "id": 1, "email": "...", "role": "admin" }, "next": "/login" }
```
Errors:
- `409 setup_already_done` — pokud `users` už není prázdná
- `400 validation_failed`
- `429 rate_limited` — víc než 5 pokusů/hod/IP

### POST `/auth/login`
Body: `{ "email": "...", "password": "...", "cf_turnstile_response": "..." }`
- `cf_turnstile_response` povinný **jen pokud** předchozí response byla `423 captcha_required` (5+ selhání v okně 5 min). Klient získá token z Turnstile widgetu.

Response 200: `{ "user": { "id":1, "email":"...", "name":"...", "role":"admin", "locale":"cs" }, "csrf_token": "..." }`
Cookie: `myinvoice_session=<token>; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000`
Errors:
- `401 invalid_credentials` — generic (chrání proti user enumeration)
- `423 captcha_required` — vyžaduje Turnstile token v dalším pokusu
- `423 captcha_failed` — Turnstile token neplatný/zamítnutý
- `429 too_many_attempts` — lockout aktivní

### POST `/auth/logout`
Response 204. Invaliduje session.

### GET `/auth/me`
Response: aktuální user nebo 401.

### POST `/auth/change-password`
Body: `{ "current_password": "...", "new_password": "...", "new_password_confirm": "..." }`
Response 204. Invaliduje **ostatní** sessions.

### POST `/auth/forgot`
Body: `{ "email": "..." }`
Response **vždy 204** (i pro neexistující email).
Rate limit: 3/email/hod.

### POST `/auth/reset`
Body: `{ "token": "...", "password": "...", "password_confirm": "..." }`
Response 204. Invaliduje všechny session uživatele.
Errors: `400 invalid_token`, `410 token_expired`.

---

## 2. Supplier (dodavatel)

### GET `/supplier`
Response: celá data dodavatele + `bank_accounts[]`.

### PUT `/supplier`
Body: pole z tabulky `supplier`. Aktualizuje řádek `id=1`.

### POST `/supplier/logo`
Multipart upload (PNG/SVG, max 1 MB).
Response: `{ "logo_path": "/storage/uploads/logo-...png" }`

### Bank accounts

#### GET `/supplier/bank-accounts`
Seznam všech.

#### POST `/supplier/bank-accounts`
Body:
```json
{
  "currency": "CZK",
  "account_number": "1000000005",
  "bank_code": "0100",
  "bank_name": "Komerční banka",
  "iban": null,
  "bic": null,
  "is_default": true
}
```
Pro EUR: `account_number/bank_code = null`, `iban` povinný.

#### PUT `/supplier/bank-accounts/{id}`
#### DELETE `/supplier/bank-accounts/{id}` (jen pokud nepoužívá žádná faktura)

---

## 3. Clients

### GET `/clients`
Query: `q`, `filter[archived]=0|1`, paginated.
Response item:
```json
{
  "id": 42, "company_name": "ACME s.r.o.", "ic": "12345678",
  "main_email": "...", "language": "cs", "currency_default": "CZK",
  "reverse_charge": false, "active_projects_count": 3
}
```

### GET `/clients/{id}`
Plný detail + `billing_emails[]` + `projects[]` (preview, max 10).

### POST `/clients`
Body — viz sloupce `clients` (bez billing emailů — ty jsou na úrovni zakázky). Vrací 201 + Location.

### PUT `/clients/{id}`
### POST `/clients/{id}/archive` → `204`
### POST `/clients/{id}/unarchive` → `204`

### POST `/clients/lookup-ares`
Body: `{ "ic": "12345678" }`
Response:
```json
{
  "found": true,
  "data": {
    "company_name": "ACME s.r.o.",
    "street": "Václavské náměstí 1",
    "city": "Praha",
    "zip": "11000",
    "country_iso2": "CZ",
    "ic": "12345678",
    "dic": "CZ12345678"
  }
}
```
Cache 24h.

### POST `/clients/lookup-vies`
Body: `{ "vat_id": "CZ12345678" }`
Response: `{ "valid": true, "name": "...", "address": "...", "fetched_at": "..." }`

---

## 4. Projects (zakázky)

### GET `/clients/{client_id}/projects`
Seznam zakázek klienta. Bez paginace (typicky < 20).

### GET `/projects`
Cross-client seznam (např. dashboard widget „Aktivní zakázky"). Paginated.

### GET `/projects/{id}`
Detail + `client` (embed) + statistika (`invoiced_total_year`, `invoiced_total_month`, `last_invoice_date`).

### POST `/projects`
Body:
```json
{
  "client_id": 42,
  "name": "Údržba webu 2026",
  "payment_due_days": 14,
  "project_number": "P-2026-001",
  "contract_number": "S-12/2025",
  "budget_total": 500000,
  "budget_yearly": 200000,
  "budget_monthly": 20000,
  "hourly_rate": 1500,
  "currency": "CZK",
  "status": "active",
  "billing_emails": [
    { "position": 1, "email": "ucetni@acme.cz",  "label": "účetní" },
    { "position": 2, "email": "pm@acme.cz",      "label": "PM" },
    { "position": 3, "email": "asistent@acme.cz", "label": null }
  ]
}
```

### PUT `/projects/{id}`
### POST `/projects/{id}/archive`

---

## 5. Invoices (faktury)

### GET `/invoices`
Query:
- `filter[status]` (`draft`, `issued`, `sent`, `paid`, `cancelled`, lze čárkou víc)
- `filter[type]` (`invoice`, `proforma`, `credit_note`, `cancellation`, lze čárkou víc; default vše)
- `filter[client_id]`, `filter[project_id]`, `filter[parent_invoice_id]`
- `filter[year]=2026`, `filter[month]=4`
- `filter[overdue]=1`, `filter[unpaid_only]=1`
- `q` = hledání ve `varsymbol`, `client.company_name`
- `sort` (default: `-issue_date,-id`)

### GET `/invoices/{id}`
Plný detail:
```json
{
  "id": 123, "varsymbol": "2026040001", "status": "issued",
  "client": { "id": 42, "company_name": "ACME", ... },
  "project": { "id": 7, "name": "Údržba webu 2026", "hourly_rate": 1500 },
  "bank_account": { "id": 1, "currency": "CZK", "account_number": "1000000005", "bank_code": "0100" },
  "issue_date": "2026-04-30", "tax_date": "2026-04-30", "due_date": "2026-05-07",
  "currency": "CZK", "reverse_charge": false, "language": "cs",
  "items": [
    { "id":1, "description":"Konzultace 4/2026", "quantity":10, "unit":"h",
      "unit_price_without_vat":1500, "vat_rate_id":1, "vat_rate_snapshot":21.00,
      "total_without_vat":15000, "total_vat":3150, "total_with_vat":18150,
      "linked_work_report_id": null, "order_index": 0 }
  ],
  "work_report": null,
  "totals": { "without_vat":15000, "vat":3150, "with_vat":18150, "rounding":0 },
  "vat_breakdown": [ { "rate":21.00, "base":15000, "vat":3150 } ],
  "snapshots": { ... },     // jen pro issued+
  "sent_at": null, "paid_at": null,
  "created_at": "2026-04-30 10:15:00"
}
```

### POST `/invoices`
Body (vytvoření draftu):
```json
{
  "client_id": 42,
  "project_id": 7,                  // může být null
  "bank_account_id": 1,             // pokud null, dosadí se default pro currency
  "issue_date": "2026-04-30",       // default today
  "tax_date": "2026-04-30",         // default today
  "due_date": null,                 // pokud null, dosadí se issue + project.payment_due_days
  "currency": "CZK",
  "reverse_charge": false,          // default z klienta
  "language": "cs",
  "items": [
    { "description": "Konzultace 4/2026", "quantity": 10, "unit": "h",
      "unit_price_without_vat": 1500, "vat_rate_id": 1, "order_index": 0 }
  ],
  "work_report": null               // viz dále
}
```
Response: 201 + plný detail.

### POST `/invoices/{id}/clone`
Body (volitelný):
```json
{ "increment_month_in_descriptions": true,   // default true
  "issue_date": "2026-05-30" }               // default today
```
Vytvoří **nový draft** podle zdrojové faktury (kopie všech položek a work_report). Pokud `increment_month_in_descriptions=true`:
- regex `/\b(\d{1,2})\/(\d{4})\b/` na `description` u `items[]` a `title` + `description` u `work_report`
- M/Y → (M+1)/(Y) nebo (1)/(Y+1) pokud M=12

Response: 201 + detail nové faktury.

### PUT `/invoices/{id}`
Edit draftu. Body stejný jako POST. Pokud `status != 'draft'` → 409.

### DELETE `/invoices/{id}`
Smazání draftu. Pokud `status != 'draft'` → 409.

### POST `/invoices/{id}/issue`
Přechod draft → issued. Vygeneruje `varsymbol`, zapíše snapshots klienta/dodavatele/banky. Vrací plný detail.

### POST `/invoices/{id}/cancel`
Zrušení vystavené faktury. Body:
```json
{
  "mode": "internal" | "credit_note",   // povinné
  "reason": "..."                        // volitelný textový důvod do activity logu
}
```
- `mode=internal` — vytvoří záznam typu `cancellation` s `parent_invoice_id`, na původní faktuře nastaví `cancelled_at`. Pro klienta žádný doklad.
- `mode=credit_note` — vytvoří **draft** typu `credit_note` se zkopírovanými zápornými položkami. Vrátí jeho ID, user musí v editoru zkontrolovat částky a kliknout `/invoices/{credit_id}/issue`. Původní faktura se označí jako `cancelled` až po vystavení dobropisu.

Response: pokud `mode=credit_note` vrací `{ "credit_note_id": 145, "edit_url": "/invoices/145" }`, jinak `{ "cancellation_id": 144 }`.

### POST `/invoices/{id}/mark-paid`
Body: `{ "paid_at": "2026-05-05" }` (default today). Přechod issued/sent → paid.

### POST `/invoices/{id}/issue-final`
**Pouze pro proformu se statusem `paid`.** Vystaví finální daňový doklad k zaplacené záloze.
Body:
```json
{
  "tax_date": "2026-05-15",                   // default today
  "due_date": "2026-05-15",                   // default today
  "advance_paid_amount": null                 // null = celá částka proformy; jinak custom
}
```
Vytvoří **draft** typu `invoice` s:
- `parent_invoice_id` = id této proformy
- kopie všech položek z proformy
- `advance_paid_amount` z body (default = `proforma.total_with_vat`)
- `amount_to_pay` = `total_with_vat - advance_paid_amount` (typicky 0)

Response 201: detail nového draftu. User pak zkontroluje a vystaví přes `/issue`.

### POST `/invoices/bulk-reissue`
Hromadné „vystavit znovu pro další měsíc". Body:
```json
{
  "invoice_ids": [101, 102, 103, 104, 105],
  "increment_month_in_descriptions": true,   // default true
  "issue_date": null                         // null = today
}
```
Pro každou fakturu vytvoří draft (logika klonování + auto-increment měsíce v popiscích). **Žádný draft není automaticky vystaven ani odeslán.**

Response 200:
```json
{
  "created": [
    { "source_id": 101, "draft_id": 201 },
    { "source_id": 102, "draft_id": 202 },
    ...
  ],
  "errors": []
}
```
Activity log: `invoice.reissued_bulk` s payload obsahujícím seznam.

### GET `/invoices/{id}/pdf`
Response: `application/pdf`, `Content-Disposition: inline; filename="Faktura-26-04-001.pdf"`.
Query: `?download=1` → `attachment`.
Query: `?regenerate=1` → ignoruje cache, vyrenderuje znovu.

### GET `/invoices/{id}/preview`
HTML preview (pro náhled v UI bez PDF). Sdílí Twig šablonu s PDF.

### POST `/invoices/{id}/send`
Body:
```json
{
  "to": ["billing@acme.cz"],         // override; default: client.main_email + project.billing_emails (z project_billing_emails)
  "cc": [],
  "bcc": [],
  "subject_override": null,          // pokud null, použije šablonu
  "body_override": null,
  "language": null                   // pokud null, dle invoice.language
}
```
Response 200:
```json
{ "sent_to": ["..."], "sent_at": "2026-04-30 10:30:00", "message_id": "..." }
```

### POST `/invoices/{id}/send-test`
**Test odeslání faktury** — pošle fakturu **pouze na `cfg.smtp.from_email`** (typicky odesílatel sám sobě). Užitečné pro:
- náhled, jak email vypadá v inboxu (formátování, příloha, DKIM)
- ověření SMTP konfigurace bez rizika spamování klienta
- kontrola DKIM/SPF hlaviček před prvním ostrým sendem

Body: prázdné nebo:
```json
{
  "language": null,                  // pokud null, dle invoice.language
  "subject_prefix": "[TEST] "        // default; přidá se před subject
}
```

Recipients jsou **vždy** `cfg.smtp.from_email` — body parameter `to` je ignorován. CC/BCC se nepoužívá.

Response 200:
```json
{
  "sent_to": ["billing@supplier.example"],
  "sent_at": "2026-04-30 10:30:00",
  "message_id": "...",
  "is_test": true
}
```

**Důsledky:**
- `invoice.sent_at` se **nenastaví** (test neovlivní stav faktury)
- `invoice.status` se **nezmění**
- Activity log: `email.sent_test` (rozlišený od `email.sent`)
- Funguje i pro draft (na rozdíl od běžného `/send`, který vyžaduje `issued`+)

### GET `/invoices/{id}/activity`
Activity log filtrovaný na tuto fakturu.

---

## 6. Work reports (výkaz víceprací)

### POST `/invoices/{id}/work-report`
Body:
```json
{
  "title": "Vícepráce za měsíc 4/2026",
  "project_id": 7,                   // default = invoice.project_id
  "items": [
    { "description": "Refaktor login flow", "hours": 4.5, "rate": 1500, "order_index": 0 },
    { "description": "Bugfix QR generátor", "hours": 1.0, "rate": 1500, "order_index": 1 }
  ],
  "auto_create_invoice_item": true   // pokud true, přidá/aktualizuje řádek faktury "Vícepráce za měsíc M/Y"
}
```
Response 201 + detail výkazu.

### PUT `/invoices/{id}/work-report`
Edit. Stejné body. Recompute sumy.

### DELETE `/invoices/{id}/work-report`
Smaže výkaz. Volitelně smaže i navázanou položku faktury (`?remove_linked_item=1`).

---

## 7. Codebooks

### GET `/codebooks/vat-rates`
Query: `?country=CZ&active_on=2026-04-30`. Default: aktivní dnes pro CZ.

### GET `/codebooks/countries`
### GET `/codebooks/currencies`

(Vše cacheable ve frontend store na celou session, mění se zřídka.)

---

## 8. Activity log

### GET `/activity-log`
Jen pro role `admin`.
Query: `filter[user_id]`, `filter[action]`, `filter[entity_type]`, `filter[entity_id]`, `from`, `to`, paginated.

---

## 8b. Admin / Settings (admin only)

### POST `/admin/smtp/test`
Pošle testovací email s aktuální `cfg.smtp` konfigurací.
Body: `{ "to": "test@example.com" }`
Response 200: `{ "success": true, "smtp_log": "...", "duration_ms": 1234 }`
Response 502: `{ "success": false, "error": "535 Authentication failed", "smtp_log": "..." }`

### GET `/admin/security/ip-allowlist`
### PUT `/admin/security/ip-allowlist`
Body: `{ "enabled": true, "mode": "block", "allow": ["192.168.1.0/24", "2001:db8::/56"], "apply_to": "all" }`
Validace každého CIDR výrazu před uložením.

### GET `/admin/security/blocked-ips`
Posledních 50 zablokovaných pokusů z `activity_log` (`security.ip_blocked`).

### GET `/admin/sessions`
Seznam aktivních sessions (per user).

### DELETE `/admin/sessions/{id}`
Force logout konkrétní session.

---

## 9. Users (admin only)

### GET `/users`
### POST `/users` — `{ email, name, role, locale, password }` (initial password emailem)
### PUT `/users/{id}` — kromě hesla
### POST `/users/{id}/reset-password` — pošle reset link
### DELETE `/users/{id}` — soft (`is_active=0`); nikdy hard delete (FK do `activity_log`)

---

## 10. Rate limity

| Endpoint | Limit |
|---|---|
| `POST /auth/login` | 10/min/IP, navíc brute-force guard per email+IP/24 |
| `POST /auth/forgot` | 3/hod/email, 10/hod/IP |
| `POST /auth/reset` | 5/hod/IP |
| `POST /clients/lookup-ares` | 30/min/user (chrání ARES) |
| `POST /clients/lookup-vies` | 30/min/user |
| Ostatní mutace | 60/min/user |
| GET endpointy | 300/min/user |

Rate-limit response 429:
```json
{ "error": { "code": "rate_limited", "message": "...", "retry_after": 45 } }
```
Header: `Retry-After: 45`.

---

## 11. Auth-free routes (bez `AuthMiddleware`)

- `POST /auth/login`
- `POST /auth/forgot`
- `POST /auth/reset`
- `GET /codebooks/*` (volitelně, pomáhá login screenu pro lokalizaci)
- `GET /health` → `{ "status": "ok", "db": true, "redis": true }`

---

## 12. CSRF

Všechny `POST/PUT/PATCH/DELETE` mimo `/auth/login` (a forgot/reset) vyžadují header `X-CSRF-Token`. Token získá klient z odpovědi `/auth/login` nebo `/auth/me` a Pinia store ho automaticky přidá axios interceptorem.

---

## 13. Příklad full-flow: vystavení faktury z předchozí

```
1.  GET  /invoices?client_id=42&sort=-issue_date&per_page=1
        → vezmu posledni fakturu, id=120
2.  POST /invoices/120/clone
        → vrati novou fakturu id=121, status=draft, varsymbol=null,
          popisky maji zvedly mesic (3/2026 -> 4/2026)
3.  PUT  /invoices/121
        → uzivatel upravi mnozstvi, polozky
4.  PUT  /invoices/121/work-report
        → updatuje vykaz vicepraci (pripadne)
5.  POST /invoices/121/issue
        → varsymbol=2026040002, snapshots ulozeny, status=issued
6.  GET  /invoices/121/pdf
        → PDF ke stazeni
7.  POST /invoices/121/send
        → email s PDF prilohou na main + billing emails
        → status=sent, sent_at nastaven
```
