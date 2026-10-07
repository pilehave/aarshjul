<?php
declare(strict_types=1);

/** Adressen på en fil i public/assets med ændringstidspunktet, så browseren henter en ny version efter ændringer */
function asset(string $file): string
{
    return 'assets/' . $file . '?v=' . @filemtime(__DIR__ . '/../public/assets/' . $file);
}

function page_header(string $title): void
{
    $flash = flash();
    ?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Årshjul</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 16 16%27%3E%3Ccircle cx=%278%27 cy=%278%27 r=%277%27 fill=%27%23e30613%27/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">◔ Årshjul</a>
<?php if ($user = Auth::user()): ?>
  <form method="post" action="logout.php" class="userbox">
    <?= csrf_field() ?>
    <a href="account.php" title="<?= h($user['email'] . ' · ' . Auth::ROLES[$user['role']]) ?>"><?= h($user['name']) ?></a>
    <button class="btn small" type="submit">Log ud</button>
  </form>
<?php endif; ?>
</header>
<main>
<?php if ($flash): ?>
  <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif;
}

/** Felterne til en ny adgangskode, der skal tastes to gange (se Auth::validatePassword). */
function password_fields(): void
{
    ?>
    <label>Ny adgangskode<input type="password" name="password" required minlength="12" maxlength="256" autocomplete="new-password"></label>
    <label>Gentag ny adgangskode<input type="password" name="password2" required minlength="12" maxlength="256" autocomplete="new-password"></label>
    <p class="muted">Mindst 12 tegn med mindst ét stort bogstav, ét lille bogstav og ét tal. Specialtegn er tilladt.</p>
<?php
}

function page_footer(): void
{
    ?></main>
<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
<?php
}
