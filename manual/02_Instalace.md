# 2. Instalace

> Tato kapitola je technická — určená pro osobu, která systém nasazuje (IT
> administrátor, hostingový tým). Běžný uživatel ji může přeskočit.

Nabízíme dvě cesty: **Docker** (nejrychlejší, doporučeno pro nové instalace)
nebo **nativní install** (PHP + MariaDB + web server, tradiční hosting).

## 2.1 Docker (3 minuty)

Předpoklady: **Docker Desktop** (Windows / macOS) nebo **Docker Engine
+ compose-plugin** (Linux).

```bash
git clone <repo-url> myinvoice
cd myinvoice

# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript `docker-install` postupně:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis,
   randomized `app.pepper` + `secret_encryption_key`, dev-friendly cookies pro
   HTTP loopback)
3. Postaví image `myinvoice:latest` (multi-stage: Vue build → composer →
   PHP 8.5 + Apache)
4. Spustí stack: **app** (Apache:80 → host:8080) + **db** (MariaDB 11)
5. Počká, až bude DB healthy, a spustí migrace

**Po dokončení otevři: 👉 http://localhost:8080**

V prohlížeči naskočí setup wizard — viz [3. První spuštění](03_Setup_wizard.md).

### 2.1.1 Změna portu

Edituj `.env` (vznikl po prvním spuštění):

```
APP_PORT=9000          # místo 8080
DB_PORT=3308           # místo 3307 (vázán jen na 127.0.0.1)
```

a `docker compose up -d`. URL pak `http://localhost:9000`.

### 2.1.2 Daily ops

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data v named volumes přežijí)
docker compose down -v                               # stop + WIPE volumes (ZNIČÍ DB!)
docker compose logs -f app                           # live logs
docker compose exec app bash                         # shell do kontejneru
docker compose exec app php api/bin/migrate.php      # CLI uvnitř kontejneru
cmd/docker-build.sh --no-cache                       # rebuild image (po PHP/JS změnách)
```

### 2.1.3 Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastav `redis.enabled => true`. Restart appky.

## 2.2 Nativní install (5 minut)

Předpoklady:

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `iconv`, `gd`
- **MariaDB 10.6+** (doporučeno 11.x)
- **Composer 2.x**, **Node.js 20+**, **pnpm 9+**
- **Redis** (volitelné — fallback na MariaDB MEMORY)
- Web server: **IIS** nebo **Apache** (oba podporované, repo má `web.config`
  i `.htaccess`)

### 2.2.1 Klon a konfigurace

```bash
git clone <repo-url> myinvoice
cd myinvoice
cp cfg.sample.php cfg.php
```

Otevři `cfg.php` a vyplň:

- `db.user` / `db.pass` — připojení k MariaDB
- `app.pepper` — vygeneruj `openssl rand -base64 32`
- `smtp.host` / `user` / `pass` — odchozí pošta
- `captcha.site_key` / `secret_key` — z dash.cloudflare.com → Turnstile
- `ip_allowlist.allow` — volitelné, doporučeno v produkci

### 2.2.2 Vytvoř databázi

```bash
mysql -u root -p -e "CREATE DATABASE myinvoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2.2.3 Backend + migrace

```bash
cd api && composer install && cd ..
php api/bin/migrate.php
```

### 2.2.4 Frontend build

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
```

### 2.2.5 Web server

- **IIS** — `web.config` v rootu repa nakonfiguruje rewrite + statiku.
- **Apache** — `.htaccess` v rootu repa, vyžaduje `mod_rewrite`, `mod_headers`.

## 2.3 Po instalaci

Otevři aplikaci v prohlížeči — pokračuj na [3. První spuštění](03_Setup_wizard.md).

## 2.4 CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/reset.php                # smaže všechna user-data (vyžaduje "ANO")
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
```

### 2.4.1 Cron skripty

V `cmd/` jsou připravené `.cmd` (Windows Task Scheduler) i `.sh` (Linux cron) wrappery:

| Skript | Doporučená frekvence |
|---|---|
| `cron-cleanup` | 1× denně 03:00 |
| `cron-backup` | 1× denně 02:00 |
| `cron-bank-scan` | každých 30 min |
| `cron-send-reminders` | 1× denně 09:00, Po–Pá |

Detaily v `cmd/README.md`.
