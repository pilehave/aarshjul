# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Årshjul ("year wheel") is a proof-of-concept planning app. It uses plain PHP 8.1+ with PDO against MySQL/MariaDB. There is no framework, no Composer dependencies, no build step and no linter. A small dependency-free test runner lives in `tests/`. The UI, code comments, commit messages and README are in Danish. Keep new text in Danish.

## Commands

Docker setup (Windows/Docker Desktop). The project folder is mounted into the container, so edits apply immediately:

```powershell
copy .env.example .env             # first time only
docker compose up -d --build       # web :8080, phpMyAdmin :8081, MariaDB :3306
docker compose logs -f web         # Apache/PHP log
docker compose down -v             # wipe DB; db/init/ scripts re-run on next up
```

To run without Docker, use `php -S localhost:8080 -t public`. DB credentials come from `config.php`. You can override them with env vars (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, optionally prefixed `AARSHJUL_`) or with a `config.local.php` that returns an array.

Syntax check: `php -l <file>`. Inside Docker, run `docker compose exec web php -l <file>`.

Tests: `docker compose exec web php tests/run.php` (exit code 1 on failure). Tests live in `tests/*Test.php` and use `test()`, `assert_same()`, `assert_true()`, plus the helpers `ev()` (a fake event row) and `set_start_month()` (sets `Settings` without a DB). They cover pure logic only and never touch the database. `.github/workflows/test.yml` runs `php -l` on every file and then the tests, on every push/PR.

## Schema changes

`db/init/` only runs when the `db_data` volume is empty. Every schema change needs updates in two places:

1. `sql/schema.sql`: the full schema, used for fresh databases. Also add the new table to its `DROP TABLE IF EXISTS` line.
2. A new `sql/migrations/YYYY-MM-DD-<name>.sql` for existing databases, written idempotently (`CREATE TABLE IF NOT EXISTS`, …). Apply it manually:
   ```powershell
   Get-Content sql/migrations/<file>.sql | docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
   ```

Update `sql/seed.sql` too if the change affects the sample data.

## Architecture

- **Request flow:** each page in `public/` is its own entry point. It `require`s `src/bootstrap.php`, which sets up the session, timezone, config and every `src/` class, and defines global helpers: `db()`, `h()`, `csrf_field()`/`require_post_csrf()`, `flash()`, `redirect()`, `date_da()`, `selected_year()`, `year_bounds()`, `year_label()`. HTML pages also `require` `src/layout.php` (`page_header()`/`page_footer()`). Forms use POST + CSRF, then redirect with a flash message (PRG). The `year` query parameter is passed along between pages.
- **Domain classes** in `src/` are `final` classes with static methods. `save`/`saveAll` methods return an array of error messages (or `[id, errors]`) instead of throwing.
- **Events are series, not dates.** A row in `events` holds a recurrence rule. `Recurrence::occurrences($event, $from, $to)` computes concrete dates on the fly. Only per-occurrence data is stored, keyed by `(event_id, occurrence_date)`: checkmarks in `event_completions`, and notes/links/files in `occurrence_notes`/`occurrence_links`/`occurrence_files` (loaded by `Events::occurrenceData()`, saved via `action.php` `do=note`). `Events::occurrencesForYear()` is the central function that expands every series for one wheel year and adds `done`, `overdue`, `blocked`, `prereqs`, `note`, `links` and `files`.
- **Dependencies:** an occurrence's prerequisite is the latest occurrence of the other event on or before its date, up to one year back (`Events::prerequisites`). The server enforces this when an occurrence is checked off (`public/action.php`). PHP rejects cycles in `Events::wouldCreateCycle`; the DB does not enforce them.
- **Wheel year ≠ calendar year:** `Settings::startMonth()` sets where the year starts. Always derive date ranges from `year_bounds($year)` and labels from `year_label($year)`; never hardcode Jan 1–Dec 31. `Settings` falls back to defaults if the `settings` table is missing.
- **Rendering:** `Wheel::svg()` builds the wheel as an inline SVG on the server. `public/wheel_svg.php` serves that same SVG for export. Clicking an occurrence opens a modal in `public/assets/app.js`. Its data comes from the `$details` array in `public/index.php`, keyed the same way as `data-occ` in `Wheel`. Keep the two in sync.
- **Exports:** `src/Xlsx.php` is a hand-written .xlsx writer that needs the `zip` extension. PNG and PDF are generated client-side in `app.js`, using the bundled `public/assets/vendor/jspdf.umd.min.js`. Do not use a CDN.
- **Files:** uploads are stored under random names in `storage/uploads/` and served only through `public/download.php`. The web root must be `public/`.
- **Frontend:** `public/assets/app.js` is a single vanilla-JS file divided into commented sections (`// --- Formular: … ---`, `// --- Forside: … ---`, `// --- Eksport ---`). There is no bundler.
