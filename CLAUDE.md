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
- **Zoom:** `src/Period.php` turns `?year` + `?zoom` (`q1`–`q4` = quarter of the wheel year, following the start month, or `YYYY-MM` = month) into a period (`kind`, `year`, `zoom`, `from`, `to`, `quarter`, `month`). `index.php`, `wheel_svg.php` and `export_xlsx.php` use `Events::occurrencesBetween()` and `Wheel::svg($year, $occ, $links, $period)` for that period, and `$q()` in `index.php` keeps `zoom`. A month's wheel year comes from the month itself. For year and quarter, the outer rings show months and week numbers. For a month, they show weeks ("Uge 41") and days with a weekday letter, with weekends shaded. `Wheel::span()` scales `MIN_SPAN` to the period length (minimum 1 day).
- **Rings:** `Settings::ringMode()` (`auto`, `category`, `person`) decides how `Wheel::rings()` groups occurrences into rings. Each ring has lanes, and overlapping series get extra lanes. Every lane has the same width, so a ring with overlaps gets wider. Fixed rings are sorted by name (Danish collation via `intl`'s `Collator` when available), with the first ring outermost. They get a name band (`RING_NAME_BAND`) with the name four times, once in the middle of each quarter. The band is always shown per person, and per category only if `Settings::ringNames()`. `categories.ring_color` (nullable) is the ring background in category mode, and `Events::all()` exposes it as `category_ring_color`. In tests, `set_setting()` sets a setting without a DB.
- **Rendering:** `Wheel::svg()` builds the wheel as an inline SVG on the server. `public/wheel_svg.php` serves that same SVG for export. Clicking an occurrence opens a modal in `public/assets/app.js`. Its data comes from the `$details` array in `public/index.php`, keyed the same way as `data-occ` in `Wheel`. Keep the two in sync.
- **Login:** `bootstrap.php` calls `require_login()` for every web request unless the page defines `const PUBLIC_PAGE = true;` before requiring it (only `login.php` and later the reset/iCal pages). Never add `PUBLIC_PAGE` to a page that shows or changes data. `src/Auth.php` holds the user (`Auth::user()`), roles in ascending order (`Auth::ROLES`: reader < contributor < admin, checked with `Auth::can()`/`require_role()`), login throttling (`login_attempts`) and `Auth::validatePassword()`. The session stores only `user_id` and a fingerprint of the password hash, so a password change or deactivation logs other sessions out. Redirect targets from user input must go through `local_path()`. Roles per page: any logged-in user (reader) gets `index.php`, `wheel_svg.php`, `export_xlsx.php` and `download.php`. `action.php` calls `require_role('contributor')`. `event.php`, `categories.php`, `people.php`, `settings.php` and `users.php` call `require_role('admin')`. A new page must call `require_role()` right after the requires if readers should not use it. Hiding buttons in `index.php`/`app.js` (`$isAdmin`, `$canEdit`, `data-can-edit` on the modal) is cosmetic only; the server check is what counts. `src/Users.php` manages users on `users.php`. Users are deactivated, never deleted, and an admin cannot demote or deactivate themselves. Create the first admin with `php bin/create-admin.php`. `src/PasswordReset.php` handles one-time links for "Glemt adgangskode" (`forgot.php`) and invitations (`users.php`), both completed on `reset.php`. Only the sha256 of a token is stored in `password_resets`. A new link expires the user's older ones, and `Auth::setPassword()` deletes them. `account.php` lets users change their own password.
- **Who and when:** `event_completions.completed_by`, `occurrence_notes.updated_by`, `occurrence_links.created_by` and `occurrence_files.uploaded_by` reference `users` (`ON DELETE SET NULL`; NULL for data from before login existed). The name shown is the linked person's name, else `users.name` (`Events::JOIN_PERSON`/`USER_NAME`). Fetch timestamps as `UNIX_TIMESTAMP(...)` and format them in PHP, because MariaDB runs in UTC and PHP in Europe/Copenhagen. Texts come from `Events::completedText()`, `noteText()` and `uploadedText()`. `saveOccurrence()` takes the user id and `$isAdmin`. It changes `updated_by` only when the note text changes, keeps unchanged links via `Events::diffLinks()`, and lets contributors delete only their own files. When testing mail flows while `.env` points at Simply, never trigger mail from the web container. Run a CLI script with `docker compose exec -e SMTP_HOST=mailpit -e SMTP_PORT=1025 -e SMTP_SECURE=none -e SMTP_USER= -e SMTP_PASS= …` instead.
- **Mail:** `src/Mailer.php` is a hand-written SMTP client (STARTTLS/SSL, AUTH PLAIN/LOGIN, base64 text body). `Mailer::send()` returns error messages. Settings are `smtp_*`, `mail_from*` and `app_url` in `config.php`. Build links in mails from `config('app_url')`, never from the `Host` header. In Docker, mail goes to Mailpit (http://localhost:8025) unless `SMTP_HOST` is set in `.env`. Production uses smtp.simply.com:587 with STARTTLS. Test the setup with `php bin/send-test-mail.php <address>`.
- **Exports:** `src/Xlsx.php` is a hand-written .xlsx writer that needs the `zip` extension. PNG and PDF are generated client-side in `app.js`, using the bundled `public/assets/vendor/jspdf.umd.min.js`. Do not use a CDN.
- **Files:** uploads are stored under random names in `storage/uploads/` and served only through `public/download.php`. The web root must be `public/`.
- **Frontend:** `public/assets/app.js` is a single vanilla-JS file divided into commented sections (`// --- Formular: … ---`, `// --- Forside: … ---`, `// --- Eksport ---`). There is no bundler.
