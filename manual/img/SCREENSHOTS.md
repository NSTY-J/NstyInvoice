# Screenshoty manuálu

Soubory v tomto adresáři po konverzi PNG → WEBP. Reference v MD souborech v
`manual/`. Po doručení nových PNG spusť:

```bash
php tools/convertImagesToWebp.php    # PNG → WEBP q80
php tools/generateManualHtml.php     # regenerace HTML
```

## Hotovo (34)

| Soubor | Popis |
|---|---|
| `01_dashboard.webp` | Přehled (dashboard) — KPI tiles, koláče, line chart |
| `03_setup_admin.webp` | Setup wizard krok 1 — Administrátor |
| `03_setup_dodavatel.webp` | Setup wizard krok 2 — Dodavatel s ARES |
| `03_setup_sample.webp` | Setup wizard krok 3 — sample data checkbox |
| `04_2fa.webp` | 2FA výzva při loginu (jen kap. 16 — 2FA sjednoceno) |
| `04_login.webp` | Přihlašovací obrazovka |
| `04_profil.webp` | Můj profil — sdílí kap. 4 i 15 |
| `04_reset.webp` | Reset hesla |
| `05_dashboard.webp` | Přehled (kapitola 5) |
| `06_klient_detail.webp` | Detail klienta |
| `06_klient_novy.webp` | Modal nového klienta s ARES |
| `06_klienti_list.webp` | Seznam klientů |
| `07_zakazka_detail.webp` | Detail zakázky |
| `07_zakazka_novy.webp` | Editor nové zakázky |
| `07_zakazky_list.webp` | Seznam zakázek |
| `08_faktury_list.webp` | Seznam faktur |
| `09_editor.webp` | Editor faktury |
| `09_email.webp` | Náhled e-mailu odeslaného klientovi (kap. 10.4) |
| `09_vykaz_vicepraci.webp` | Editor s otevřeným výkazem víceprací |
| `10_detail.webp` | Detail vystavené faktury |
| `10_qr_platba.webp` | QR platba v PDF |
| `11_banka_upload.webp` | Banka → Nahrát výpis |
| `12_sablona.webp` | Editor e-mailové šablony invoice_reminder |
| `12_upominka_btn.webp` | Detail faktury — tlačítko Upomínka |
| `12_upominka_bulk.webp` | Faktury filter „Po splatnosti" + bulk Upomínka |
| `13_exporty.webp` | Systém → Exporty |
| `14_dodavatel_novy.webp` | Modal nového dodavatele s ARES |
| `14_dodavatele_list.webp` | Systém → Dodavatelé seznam |
| `14_supplier_switcher.webp` | Horní lišta s přepínačem dodavatele |
| `15_activity.webp` | Systém → Activity log |
| `15_ciselniky_dph.webp` | Číselníky → záložka DPH |
| `15_ciselniky_meny.webp` | Číselníky → záložka Měny |
| `15_emails_list.webp` | Systém → E-mail šablony seznam |
| `15_users.webp` | Systém → Uživatelé |
| `16_2fa_setup.webp` | Aktivace 2FA — QR kód + zálohové kódy |

**Vyřazeno z manuálu** (nepotřebné — jsou už pokryté duplikátně nebo zobecněné):
- ~~`14_dodavatel_detail.webp`~~ — sekce 14.5 popisuje záložky textově
- ~~`15_profil.webp`~~ — sjednoceno s `04_profil.webp`
- ~~`16_2fa_login.webp`~~ — sjednoceno s `04_2fa.webp`
- ~~`08_hromadne_akce.webp`~~ — vyhozeno
- ~~`11_vypisy_list.webp`~~ — vyhozeno
- ~~`11_vypis_detail.webp`~~ — vyhozeno
- ~~`15_logo_upload.webp`~~ — feature neexistuje
- ~~`15_email_editor.webp`~~ — vyhozeno
