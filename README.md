# Årshjul (proof of concept)

Ren PHP 8.1+ og MySQL/MariaDB via PDO. Ingen framework og ingen Composer-pakker.

## Kom i gang

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
miljøvariabler (`AARSHJUL_DB_HOST`, `AARSHJUL_DB_NAME`, `AARSHJUL_DB_USER`, `AARSHJUL_DB_PASS`)
eller en `config.local.php`, der returnerer et array.

Kræver PHP-udvidelserne `pdo_mysql`, `zip` (Excel-eksport), `fileinfo` og `mbstring`.
På Apache/nginx skal webroden pege på `public/`, så `storage/` og `src/` ikke er tilgængelige.

## Funktioner

- **Årshjul** for et valgt år (SVG), med månedsring, ugenumre, "i dag"-markør og filter på person.
  Klik på en begivenhed i hjulet for at redigere den.
- **Opret, ret og slet** begivenheder med titel, farve, beskrivelse, varighed i dage.
- **Gentagelser**: ingen, ugentlig, månedlig, årlig (alle med "hver N."), bestemt ugedag i
  måneden (fx 2. tirsdag, sidste fredag i april), **sidste uge i måneden** (fx sidste uge i maj)
  og bestemt ugenummer (fx uge 8). Valgfri slutdato for gentagelsen.
- **Afhængigheder**: en begivenhed kan afhænge af én eller flere andre. Den kan først krydses af,
  når forudsætningerne er krydset af (tjekkes også på serveren). Cirkulære afhængigheder afvises.
- **Flueben** gemmes pr. forekomst, så fx årets budgetudkast kan være opfyldt uden at næste års er det.
- **Flere personer** pr. begivenhed (skrives kommasepareret, nye navne oprettes automatisk).
- **Flere links og filer** pr. begivenhed. Filer gemmes i `storage/uploads` med tilfældige navne.
- **Eksport**: Excel (.xlsx, genereres uden biblioteker), PNG, PDF (hjul + liste, via jsPDF der
  ligger lokalt i `public/assets/vendor`) og SVG.

## Opbygning

```
config.php            Konfiguration
sql/schema.sql        Tabeller
sql/seed.sql          Eksempeldata
src/Recurrence.php    Udregning af datoer ud fra gentagelsesregler
src/Events.php        Databaseadgang, validering, afhængigheder og flueben
src/Wheel.php         Tegner hjulet som SVG
src/Xlsx.php          Minimal .xlsx-skriver
public/               Webrod: index.php (hjul + liste), event.php (formular), action.php (flueben),
                      download.php (filer), export_xlsx.php, wheel_svg.php, assets/
storage/uploads/      Uploadede filer
```

## Datamodel i korte træk

En række i `events` er en *serie*. Datoerne i et år udregnes ud fra reglen og gemmes ikke.
Kun afkrydsninger gemmes (`event_completions`: begivenhed + dato). En forudsætning for en
forekomst er den seneste forekomst af den anden begivenhed på eller før datoen (op til et år tilbage).

"Sidste uge i måneden" er defineret som ugen, der starter med månedens sidste mandag.
Sæt varighed til 5 for mandag–fredag eller 7 for hele ugen.

## Kendte begrænsninger (POC)

- Ingen login eller brugerstyring.
- Korte begivenheder vises uden tekst i hjulet (titel ses ved mouse-over og i listen).
- Enkelte forekomster kan ikke flyttes eller aflyses for sig; man retter hele serien.
