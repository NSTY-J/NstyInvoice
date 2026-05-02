# 10. Faktura — PDF, QR platba, odeslání e-mailem

Vystavená faktura má **immutable PDF** — vygeneruje se v okamžiku vystavení a
od té chvíle se nemění (snapshot dodavatele, klienta, banky). Tím se zajišťuje
neměnnost dokladu i kdybyste si v Nastavení změnil adresu nebo bankovní účet.

## 10.1 Detail faktury

Klik na číslo faktury v seznamu otevře detail.

![Detail faktury](img/10_detail.webp)

Detail ukazuje:

- **Hlavičku** — variabilní symbol, typ, klient, data, částka, stav
- **Položky** — read-only zobrazení řádků
- **Náhled PDF** — embed iframe (lze otevřít na celou obrazovku)
- **Activity log** — kdo a kdy fakturu vytvořil / vystavil / odeslal / označil
  zaplacenou

### 10.1.1 Akční tlačítka (vpravo nahoře)

Závisí na stavu faktury:

| Stav | Dostupné akce |
|---|---|
| `issued` | Stáhnout PDF, Odeslat e-mailem, Označit zaplacené, Storno, Dobropis, Test odeslání, Test upomínky, **Editovat (force)** |
| `sent` | Stáhnout PDF, Odeslat znovu, Označit zaplacené, Upomínka, Dobropis |
| `reminded` | Stáhnout PDF, Další upomínka (cooldown 14 dní), Označit zaplacené |
| `paid` | Stáhnout PDF, Dobropis (vrátit peníze) |

> 💡 **Test odeslání / Test upomínky** — pošle e-mail jen na **tvůj** e-mail
> (ne klientovi). Užitečné pro vyzkoušení šablony nebo SMTP konfigurace.

## 10.2 PDF struktura

Vygenerované PDF obsahuje:

1. **Hlavičku** — logo dodavatele, jméno, adresa, IČ, DIČ, kontakt
2. **Adresát** — klient (firma + adresa + IČ + DIČ)
3. **Číslo faktury** + **typ** (Faktura / Proforma / Dobropis / Storno)
4. **Data** — vystaveno, DUZP, splatnost
5. **Bankovní spojení** — číslo účtu / IBAN, BIC, banka, variabilní symbol
6. **Položky** — tabulka (Popis / Množství / Cena / DPH / Celkem)
7. **Sumář** — mezisoučet, sleva, DPH rozpis, **CELKEM**
8. **QR platbu** — vpravo dole (CZK SPAYD nebo EUR SEPA EPC)
9. **Patičku** — text z Nastavení dodavatele (volitelný)
10. **(volitelně) 2. strana** — Výkaz víceprací

## 10.3 QR platba

![QR platba na PDF](img/10_qr_platba.webp)

Pro **CZK** se generuje **SPAYD** (Short Payment Descriptor — český národní
standard). Aplikace banky to umí přečíst (KB, FIO, Air Bank, Raiffeisen,
Revolut, Wise…).

Pro **EUR** se generuje **SEPA EPC** (European Payments Council) QR — funguje
pro všechny EUR účty v EU.

QR obsahuje:

- Číslo účtu / IBAN
- Částku v měně faktury
- Variabilní symbol
- Měnu
- Zprávu pro příjemce (varsymbol + jméno odběratele)

> ⚠️ QR se vygeneruje jen pokud bankovní účet projde **mod-11 kontrolou**
> (CZ účty) nebo **IBAN checksum** (EUR). Neplatný účet → QR se v PDF
> nezobrazí, zbytek faktury OK.

## 10.4 Odeslání e-mailem

### 10.4.1 Manuální odeslání

Tlačítko **Odeslat e-mailem** (na detailu faktury). E-mail jde na:

- `klient.hlavni_email`
- `+ zakazka.fakturacni_emaily[]` (až 3 dodatečné adresy)

Předmět + tělo e-mailu se vezme ze šablony `invoice_new` (CZ / EN podle jazyka
klienta) — viz [15. Nastavení → § 15.5](15_Nastaveni.md).

![Náhled odeslaného e-mailu klientovi](img/09_email.webp)

Po odeslání:

- Status faktury → `sent`
- V activity logu záznam `invoice.sent` s adresami příjemců

### 10.4.2 Hromadné odeslání

Z [Seznamu faktur](08_Faktury.md) vybereš více faktur a klikneš **Odeslat
klientovi (N)** — bulk action.

### 10.4.3 Odesílatel a Reply-To

Per dodavatel lze nastavit:

- **From: jméno** — co se zobrazí jako odesílatel (např. „MyWebdesign.cz s.r.o.")
- **Reply-To** — kam má klient odpovědět (např. `fakturace@mywebdesign.cz` ≠
  technická adresa, ze které jde SMTP)

Nastavuje se v **Systém → Dodavatelé → [tvůj dodavatel] → Editovat**.

## 10.5 Editace vystavené faktury (admin only)

V krajní nouzi (admin udělal v vystavené faktuře překlep, klient ji ještě
nedostal):

1. Z detailu faktury klikni **Editovat (force)** — vyžaduje admin roli.
2. Otevře se editor s URL `?force=1`.
3. Změny se uloží + původní PDF se invaliduje + zaloguje se `invoice.edit_force`
   v activity logu.

> ⚠️ **Editace vystavené faktury obecně NENÍ doporučená.** Změny snapshotů
> mohou být rozpor s tím, co klient dostal e-mailem. Preferuj **storno + nová
> faktura** nebo **dobropis**.

## 10.6 Změna bankovního účtu po vystavení

Pokud změníš bankovní účet v **Systém → Číselníky → Měny**, automaticky se
**invalidují PDF všech faktur**, které renderují bank info live (drafty +
faktury bez snapshotu). Faktury v stavu `issued` a vyšším mají immutable
`bank_snapshot` — jejich PDF zůstává s **původními** údaji (správně, klient ji
už dostal).

V activity logu uvidíš `currency.updated` s počtem invalidovaných PDF.

## 10.7 Tipy

- **PDF náhled v iframe na detailu** se neobnoví automaticky po editaci —
  refreshni stránku (F5).
- **Test odeslání** je nejlepší způsob, jak ověřit, že máš správně SMTP
  + DKIM + e-mailovou šablonu, **bez rizika**, že to půjde klientovi.
- **Jeden e-mail s víc fakturami nelze poslat** — každá faktura jde
  v samostatném mailu. (Hromadné odeslání = N e-mailů, ne jeden.)
- **Po odeslání e-mailu nejde stáhnout PDF zpět** — pokud se klient zeptá, je
  to v jeho schránce.
