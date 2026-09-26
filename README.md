# Teamtailor-radar

Laravel-applikation som hittar företag som annonserar via Teamtailor, även när karriärsidan ligger på egen domän (till exempel `career.foretag.se`), samlar alla öppna annonser och visar dem i ett filtrerbart webbgränssnitt. Fokus ligger på svenska företag.

Projektet byggs i faser. Klart hittills: Fas 0 (projektuppsättning), Fas 1 (kalibrering av fingeravtryck), Fas 2 (domänmodell), Fas 3 (skrapning), Fas 4 (verifiering), källorna A till D i Fas 5, Fas 6 (deduplicering), Fas 7 (schemaläggning och statistik) och Fas 8 (webbgränssnitt).

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

Hela miljön (webbserver, Horizon, MySQL och Redis) startas med `docker compose up -d`. Gränssnittet behöver byggda resurser (`npm install && npm run build`). Med `php artisan db:seed --class=DemoSeeder` fylls databasen med påhittade annonser att prova med.

Schemat i `routes/console.php` körs med `php artisan schedule:work` (eller cron mot `schedule:run`): skrapning varje natt, upptäckt och verifiering varje måndag, Public Suffix List varje söndag och Horizons ögonblicksbilder var femte minut.

## Kontroller

| Kommando | Vad det gör |
| --- | --- |
| `composer test` | Pest utan MySQL (SQLite i minnet) |
| `composer test:mysql` | Tester i gruppen `mysql`, kräver en MySQL-databas enligt `DB_*` |
| `composer analyse` | Larastan, nivå 6 |
| `composer lint` | Pint i kontrolläge (PSR-12 och `declare(strict_types=1)`) |
| `composer format` | Pint som rättar filerna |

Alla tester körs offline. `Http::preventStrayRequests()` är aktivt och DNS samt TLS är utbytta mot fejkade klienter i `tests/Fakes`.

## Kommandon

| Kommando | Vad det gör |
| --- | --- |
| `radar:calibrate {värdar*}` | Sparar DNS, TLS och HTTP för kända karriärsidor (se Kalibrering) |
| `radar:import-legacy {sökväg}` | Läser Python-prototypens SQLite-fil och skapar starka kandidater med källan `seed` |
| `radar:update-psl` | Hämtar Public Suffix List till `storage/app/psl/` |
| `radar:discover` | Samlar kandidater från källorna i `RADAR_SOURCES` eller `--source=` (`jobstream`, `prefixes`, `commoncrawl`). `--domains=fil.csv` lägger till basdomäner för prefixprobning, `--sync` kör allt i samma process i stället för i kön |
| `radar:verify` | Köar verifiering av nya kandidater, i batchar om 200, starka först. Avvisade kandidater och fingeravtrycksträffar utan flöde provas igen efter `RADAR_REJECTED_RECHECK_DAYS` dagar. `--source=` och `--limit=` begränsar urvalet |
| `radar:stats` | Kandidater per källa och steg, aktiva karriärsidor och annonser per källa, fördelning per typ och status, verifierade per prefix och TXT-kunder utan hittad sida |
| `radar:scrape` | Köar skrapning av alla karriärsidor som en batch och visar framsteg. `--only-active` tar bara sidor som hade annonser, `--host=` väljer sidor via kanonisk värd eller alias, `--no-wait` avslutar direkt efter köandet |

`radar:import-legacy` letar i varje tabell efter den första kolumnen som heter `host`, `hostname`, `canonical_host`, `board_host`, `domain`, `url`, `career_url`, `home_url`, `feed_url` eller `base_url`, eftersom prototypens schema inte finns i repot. Värdena normaliseras och redan kända värdar hoppas över.

Registrerbar domän räknas fram med Public Suffix List. En kopia ligger i `resources/psl/` och används tills `radar:update-psl` har hämtat en egen.

## Upptäckt och verifiering

Kandidater (möjliga karriärsidor) och verifierade karriärsidor ligger i separata tabeller. Varje kandidat har en källa och en styrka.

| Källa | Styrka | Hur |
| --- | --- | --- |
| A, omdirigeringar | stark | Vid varje skrapning sparas slutvärden för `jobs.json` och värden i flödets `home_page_url` som alias. Inga extra anrop |
| B, Platsbanken | stark | JobTech JobStream: först `/snapshot` (cirka 300 MB, läses strömmande från disk), sedan bara ändringar via `/stream?date=`. Ansökningslänkar med sökvägen `/jobs/<siffror>-` eller värd under `teamtailor.com` blir kandidater. Arbetsgivarens webbadress blir en basdomän för källa C. Annonserna sparas i `platsbanken_ads` |
| C, prefix | svag | Varje prefix i `config/radar.php` provas mot alla kända basdomäner och mot registrerbara domäner för kända egna domäner. Prefixet sparas i `source_ref` så att träffar kan räknas per prefix |
| D, Common Crawl | stark | Alla `*.teamtailor.com` i de senaste indexen (`RADAR_COMMONCRAWL_INDEXES`, standard tre) via CDX-API:t, sida för sida med en sekunds paus |

JobStreams endpoints och fält är kontrollerade mot JobStreams källkod (gitlab.com/arbetsformedlingen/job-ads/jobsearch-apis), eftersom dokumentationen inte gick att nå från utvecklingsmiljön. API:et kräver ingen nyckel. `RADAR_JOBSTREAM_API_KEY` skickas som `api-key` bara om den är satt.

Verifieringen går från billigt till dyrt:

1. **DNS.** A-post via DNS over HTTPS, med hela CNAME-kedjan. Saknas namnet blir steget `no_dns`. DNS-fel lämnar kandidaten som `new` till nästa körning. Slug-adresser under `teamtailor.com` hoppar över DNS eftersom de ligger bakom ett jokertecken.
2. **Fingeravtryck.** CNAME-kedjan eller adresserna jämförs med `Fingerprint`. Svaga kandidater utan träff avvisas här, starka går vidare.
3. **HTTP.** `jobs.json` hämtas med omdirigeringar. Bara ett läsbart jobbflöde ger `verified`.
4. **Reserv.** Svarar en stark kandidat 404 letas HTML-markörer på startsidan. En träff loggas som `fingerprint_match`, men ingen karriärsida skapas utan flöde.

## Deduplicering

Karriärsidor skapas bara via `BoardResolver`. Kanonisk värd är värden i flödets `home_page_url`, och annars värden där `jobs.json` hamnade efter omdirigeringar. Alla andra värdar som leder dit sparas som alias. Om en slug visar sig höra till en redan känd egen domän slås karriärsidorna ihop. Detsamma gäller två karriärsidor med exakt samma uppsättning aktiva annonser (minst tre). Den som upptäcktes först behålls, annonserna flyttas och den andras värd blir alias.

## Skrapning

Varje karriärsida skrapas av köjobbet `ScrapeBoard`. Det är unikt per karriärsida och gör högst en förfrågan åt gången mot samma värd.

1. `jobs.json` hämtas sida för sida (`?page=N`, 100 poster per sida) tills en sida har färre än 100 poster. Om en full sida inte ger några nya id, om en senare sida inte går att läsa eller om gränsen på 100 sidor nås, räknas läsningen som trunkerad.
2. `jobs.rss` hämtas med `?offset=N` för avdelning och distansstatus. Fel här loggas men stoppar aldrig skrapningen.
3. Annonserna sparas med upsert på `(board_id, feed_id)`. `first_seen_at` behålls, alla orter sparas i `job_posting_locations` och land normaliseras till ISO-kod.
4. Annonser som försvunnit inaktiveras, men bara efter en fullständig läsning.

Status på karriärsidan: `active` (har annonser), `empty` (flödet är tomt), `dead` (404, 410 eller svar som inte är ett jobbflöde, annonserna stängs) och `error` (tillfälligt fel, senast kända annonser behålls). Döda sidor provas igen efter `RADAR_DEAD_RECHECK_DAYS` dagar.

## Webbgränssnitt

`/` visar alla aktiva annonser, grupperade per arbetsgivare eller som platt lista. Gruppering sker på annonsens arbetsgivare och inte på karriärsidan, eftersom bemanningsföretag annonserar åt kunder. Filtren (fritext, land, ort, arbetsplats, avdelning, publiceringsdatum, nyupptäckta och typ av karriärsida) sparas i URL:en, och varje filter visar antal träffar givet de andra filtren. Antalen cachas en minut per filterkombination. `/stats` visar samma statistik som `radar:stats`.

Fritext söker i titel, arbetsgivare och beskrivning via MySQL FULLTEXT i boolean mode, med `+ord*` per ord. Det betyder att ett ord matchar i början av ord. "utvecklare" hittar därför inte "Backendutvecklare". Ord kortare än tre tecken söks med LIKE, eftersom de ligger under InnoDB:s minsta ordlängd.

Sökningen (`App\Search\JobSearch`) matchar fritexten i SQL och läser sedan en smal rad per träff via ett täckande index. Totalen, alla facetter och grupperna räknas i ett enda svep i PHP, och bara de annonser som visas laddas i sin helhet. En grupps annonser laddas när gruppen fälls ut. Testet `composer test:mysql` seedar 50 000 annonser med `DemoSeeder` och kräver att typiska sidor svarar under 300 ms (bästa av tre körningar, med resultatcachen tömd före varje körning). Ett sökord som finns i nästan alla annonser är långsammare, eftersom InnoDB:s fulltextsökning då själv blir flaskhalsen.

Gränssnittet är avsett för eget bruk. Med `RADAR_BASIC_AUTH_USER` och `RADAR_BASIC_AUTH_PASSWORD` kräver alla sidor, inklusive Livewire och Horizon, inloggning med HTTP Basic Auth. I produktion utan dessa värden svarar appen 403. Exponera den inte öppet på internet.

## Konfiguration

Alla inställningar finns i `config/radar.php` och läses från `.env` (se `.env.example`). DNS går som standard via DNS over HTTPS (`RADAR_DNS_DRIVER=doh`). Med `RADAR_DNS_DRIVER=system` används operativsystemets resolver i stället, vilket fungerar där DoH är blockerat men saknar tidsgräns.

## Kalibrering

`php artisan radar:calibrate {värdar*}` sparar för varje värd CNAME-kedja, A- och AAAA-poster, TXT-poster på rotdomänen, certifikatets utfärdare och SAN-lista, svarshuvuden för `/` och `/jobs.json` samt vilka värdar HTML-sidan laddar resurser från. Resultatet hamnar som JSON i `storage/app/calibration/`. Delar kan hoppas över med `--without=txt,tls,http`.

### Resultat (26 september 2026)

Kalibreringen gjordes i en miljö där HTTP mot Teamtailor, DNS over HTTPS och stora TXT-svar var blockerade. Därför bygger resultatet enbart på DNS via systemets resolver. Ett urval av rådata finns i `tests/Fixtures/calibration/`.

Metod: prefixen `career`, `careers`, `karriar`, `jobb`, `jobs`, `join`, `work` och `jobba` provades mot 148 företagsdomäner, främst svenska. 42 värdar pekade på Teamtailor.

* **CNAME.** Nästan alla egna domäner pekar på den gemensamma adressen `ext.teamtailor.com`. Två (`career.clasohlson.com` och `careers.airmee.com`) pekar på en unik adress på formen `<13 tecken>.ext.teamtailor.com`. `*.ext.teamtailor.com` är ett DNS-jokertecken, och alla leder vidare till `teamtailor.map.fastly.net`. Mönstret är distinkt och räcker som fingeravtryck i DNS.
* **IP-adresser.** `teamtailor.map.fastly.net` svarar med 151.101.1.242, 151.101.65.242, 151.101.129.242 och 151.101.193.242 samt motsvarande IPv6 under `2a04:4e42::/32`. Det är Fastlys anycastadresser som kan delas med andra Fastlykunder. En träff på adress (utplattad CNAME) är därför bara ett skäl att göra ett HTTP-anrop, aldrig ett bevis.
* **TXT.** `airmee.com` har posten `teamtailor=<32 hexadecimala tecken>`, vilket ser ut att vara Teamtailors verifieringspost. Det är bara en bekräftelse eftersom de flesta TXT-svar var för stora för resolvern i kalibreringsmiljön. `nordicwellness.se` har ett fristående 32-teckens hexvärde utan prefix, vars ursprung inte går att avgöra. Källa E ser möjlig ut men mönstret behöver fler bekräftelser.
* **Slug-adresser.** `*.teamtailor.com` är ett DNS-jokertecken som pekar på en lastbalanserare i AWS (`eu-pink-ecs-teamtailor-*.eu-west-1.elb.amazonaws.com`). Även påhittade slugs svarar, så DNS kan inte avgöra om en slug finns. Sådana kandidater måste gå direkt till HTTP.
* **HTML.** Ej kalibrerat. Arbetshypotesen är domänen `teamtailor-cdn.com` (`assets.`, `scripts.` och `images.` finns i DNS).
* **Certifikat.** Ej kalibrerat. TLS-trafiken i kalibreringsmiljön gick via en proxy som bytte ut certifikaten.

Mönstren finns i `App\Discovery\Fingerprint`, med en kommentar per mönster om vilka domäner det bekräftades mot. Innan Fas 4 bör `radar:calibrate` köras igen med fullt nätverk för att fylla i HTML-markörer och certifikatsnoteringar och för att få fler TXT-bekräftelser.

## Python-prototypen

Planen utgår från en Python-prototyp (`radar.py`, `web/index.html`) som skulle flyttas till `legacy/`. Den finns inte i det här repot och har därför inte flyttats.
