# Årshjul (proof of concept)

Ren PHP 8.1+ og MySQL/MariaDB via PDO. Ingen framework og ingen Composer-pakker.

## Udviklingsmiljø med Docker (Windows/Docker Desktop)

Kræver Docker Desktop. Fra projektmappen (PowerShell eller terminalen i VS Code):

```powershell
copy .env.example .env      # første gang; ret evt. adgangskoder og porte
docker compose up -d --build
```

| Tjeneste   | Adresse                 | Bemærkning                                         |
|------------|-------------------------|----------------------------------------------------|
| Webapp     | http://localhost:8080   | PHP 8.1 + Apache, webrod `public/`                 |
| phpMyAdmin | http://localhost:8081   | Logger automatisk ind som root                     |
| Mailpit    | http://localhost:8025   | Fanger alle mails under udvikling                  |
| MariaDB    | `localhost:3306`        | 10.11, data i named volume `db_data`               |

Projektmappen er mountet i containeren, så ændringer i VS Code slår igennem med det samme.
Databaseoplysningerne står i `.env` og gives til PHP som `DB_HOST`, `DB_NAME`, `DB_USER` og
`DB_PASS`. Første gang databasen oprettes, køres scripts i `db/init/` (tabeller fra
`sql/schema.sql` og eksempeldata fra `sql/seed.sql`).

```powershell
docker compose logs -f web             # Apache/PHP-log
docker compose exec web bash           # shell i webcontaineren (Composer er installeret)
docker compose down                    # stop (data bevares)
docker compose down -v                 # stop og slet databasen; init-scripts køres igen ved næste start
```

**Opdatering af en eksisterende database:** init-scripts køres kun første gang. Ændringer i skemaet
ligger som scripts i `sql/migrations/` og køres manuelt, fx:

```powershell
Get-Content sql/migrations/2026-10-03-categories.sql | docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
```

Er en port optaget (fx en lokal MySQL på 3306), så ret `DB_FORWARD_PORT`, `WEB_PORT` eller
`PMA_PORT` i `.env`.

## Kom i gang uden Docker

```bash
# 1) Database (opretter databasen "aarshjul" og fylder eksempeldata i)
mysql -u root -p < sql/schema.sql
mysql -u root -p aarshjul < sql/seed.sql
mysql -u root -p -e "CREATE USER 'aarshjul'@'localhost' IDENTIFIED BY 'aarshjul';
                     GRANT ALL ON aarshjul.* TO 'aarshjul'@'localhost';"

# 2) Start PHP's indbyggede webserver med public/ som webrod
php -S localhost:8080 -t public
```

Åbn http://localhost:8080. Databaseoplysninger står i `config.php` og kan overstyres med
miljøvariabler (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` eller med præfikset `AARSHJUL_`)
eller en `config.local.php`, der returnerer et array.

Kræver PHP-udvidelserne `pdo_mysql`, `zip` (Excel-eksport), `fileinfo` og `mbstring`.
På Apache/nginx skal webroden pege på `public/`, så `storage/` og `src/` ikke er tilgængelige.

## Mail

Mails sendes med `src/Mailer.php`, en lille SMTP-klient uden afhængigheder. Under udvikling sendes alt
til Mailpit, og mails kan ses på http://localhost:8025. Til rigtig afsendelse sættes `SMTP_HOST`,
`SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM` og `APP_URL` i `.env` (se eksemplet
med Simply.com i `.env.example`). Opsætningen kan afprøves med:

```powershell
docker compose exec web php bin/send-test-mail.php din@adresse.dk
```

## Tests

`tests/` indeholder en lille testkører uden afhængigheder. Den tester datologikken (gentagelser,
afhængigheder, forskudte år) og bruger ikke databasen.

```powershell
docker compose exec web php tests/run.php
```

Nye tests skrives i en fil `tests/<Navn>Test.php` med `test('beskrivelse', function () { ... })` og
`assert_same()`/`assert_true()`. GitHub Actions (`.github/workflows/test.yml`) kører syntakstjek og tests
ved hvert push og pull request.

## Funktioner

- **Årshjul** for et valgt år (SVG), med månedsring, ugenumre, "i dag"-markør og filter på person.
  Klik på en begivenhed i hjulet for at redigere den.
- **Opret, ret og slet** begivenheder med titel, kategori, beskrivelse, varighed i dage.
- **Kategorier** med navn og farve redigeres under knappen "Kategorier". En begivenhed vælger en
  eksisterende kategori og får dens farve. En kategori kan kun slettes, når ingen begivenheder bruger den.
- **Gentagelser**: ingen, ugentlig, månedlig, årlig (alle med "hver N."), bestemt ugedag i
  måneden (fx 2. tirsdag, sidste fredag i april), **sidste uge i måneden** (fx sidste uge i maj)
  og bestemt ugenummer (fx uge 8). Valgfri slutdato for gentagelsen.
- **Afhængigheder**: en begivenhed kan afhænge af én eller flere andre. Den kan først krydses af,
  når forudsætningerne er krydset af (tjekkes også på serveren). Cirkulære afhængigheder afvises.
- **Flueben** gemmes pr. forekomst, så fx årets budgetudkast kan være opfyldt uden at næste års er det.
- **Personer** oprettes, omdøbes og slettes under knappen "Personer". På en begivenhed vælges en eller
  flere ved at skrive navnet; de vises i feltet som "navn ×," og fjernes med krydset.
- **Flere links og filer** pr. begivenhed. Filer gemmes i `storage/uploads` med tilfældige navne.
- **Note pr. forekomst**: i modalen kan man skrive en note og tilføje links og filer, der kun gælder denne gang
  (fx "Mødet holdes på Teams" eller et referat). Noten vises i listen, i Excel-eksporten og som et lille mærke på
  buen i hjulet. Næste forekomst af samme begivenhed starter uden note.
- **Indstillinger** (tandhjulet ved "Personer"): vælg hvilken måned årshjulet starter med. Starter det
  fx i august, viser hjulet for 2026 perioden 1. august 2026 – 31. juli 2027 (vist som "2026/27").
- **Eksport**: Excel (.xlsx, genereres uden biblioteker), PNG, PDF (hjul + liste, via jsPDF der
  ligger lokalt i `public/assets/vendor`) og SVG.

## Opbygning

```
config.php            Konfiguration
docker-compose.yml    Docker-udviklingsmiljø (docker/php/, db/init/, .env.example)
sql/schema.sql        Tabeller
sql/seed.sql          Eksempeldata
sql/migrations/       Skemaændringer til eksisterende databaser
src/Recurrence.php    Udregning af datoer ud fra gentagelsesregler
src/Categories.php    Kategorier (navn + farve)
src/People.php        Personer
src/Settings.php      Indstillinger (startmåned)
src/Events.php        Databaseadgang, validering, afhængigheder og flueben
src/Wheel.php         Tegner hjulet som SVG
src/Xlsx.php          Minimal .xlsx-skriver
public/               Webrod: index.php (hjul + liste), event.php (formular), categories.php, people.php, settings.php,
                      action.php (flueben og noter pr. forekomst),
                      download.php (filer), export_xlsx.php, wheel_svg.php, assets/
storage/uploads/      Uploadede filer
```

## Datamodel i korte træk

En række i `events` er en *serie*. Datoerne i et år udregnes ud fra reglen og gemmes ikke.
Kun afkrydsninger (`event_completions`) og noter, links og filer pr. forekomst (`occurrence_notes`,
`occurrence_links`, `occurrence_files`) gemmes, alle med begivenhed + dato som nøgle. En forudsætning for en
forekomst er den seneste forekomst af den anden begivenhed på eller før datoen (op til et år tilbage).

"Sidste uge i måneden" er defineret som ugen, der starter med månedens sidste mandag.
Sæt varighed til 5 for mandag–fredag eller 7 for hele ugen.

## Kendte begrænsninger (POC)

- Ingen login eller brugerstyring.
- Korte begivenheder vises uden tekst i hjulet (titel ses ved mouse-over og i listen).
- Enkelte forekomster kan ikke flyttes eller aflyses for sig; man retter hele serien.
