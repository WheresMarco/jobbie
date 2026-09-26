# Teamtailor-radar

Laravel-applikation som hittar företag som annonserar via Teamtailor, även när karriärsidan ligger på egen domän (till exempel `career.foretag.se`), samlar alla öppna annonser och visar dem i ett filtrerbart webbgränssnitt. Fokus ligger på svenska företag.

Projektet byggs i faser. Klart hittills: Fas 0 (projektuppsättning).

## Krav

* PHP 8.4 med tilläggen `intl`, `pdo_mysql`, `pcntl` och `redis`
* Composer 2
* MySQL 8 och Redis 7 (eller Docker)

## Kom igång

```sh
cp .env.example .env
docker compose up -d mysql redis
composer install
php artisan key:generate
php artisan migrate
```

Hela miljön (webbserver, Horizon, MySQL och Redis) startas med `docker compose up -d`.

## Kontroller

| Kommando | Vad det gör |
| --- | --- |
| `composer test` | Pest utan MySQL (SQLite i minnet) |
| `composer test:mysql` | Tester i gruppen `mysql`, kräver en MySQL-databas enligt `DB_*` |
| `composer analyse` | Larastan, nivå 6 |
| `composer lint` | Pint i kontrolläge (PSR-12 och `declare(strict_types=1)`) |
| `composer format` | Pint som rättar filerna |

Alla tester körs offline. `Http::preventStrayRequests()` är aktivt.

## Konfiguration

Alla inställningar finns i `config/radar.php` och läses från `.env` (se `.env.example`). DNS går som standard via DNS over HTTPS (`RADAR_DNS_DRIVER=doh`).

## Python-prototypen

Planen utgår från en Python-prototyp (`radar.py`, `web/index.html`) som skulle flyttas till `legacy/`. Den finns inte i det här repot och har därför inte flyttats.
