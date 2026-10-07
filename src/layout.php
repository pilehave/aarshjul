<?php
declare(strict_types=1);

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
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">◔ Årshjul</a>
<?php if ($user = Auth::user()): ?>
  <form method="post" action="logout.php" class="userbox">
    <?= csrf_field() ?>
    <span title="<?= h($user['email'] . ' · ' . Auth::ROLES[$user['role']]) ?>"><?= h($user['name']) ?></span>
    <button class="btn small" type="submit">Log ud</button>
  </form>
<?php endif; ?>
</header>
<main>
<?php if ($flash): ?>
  <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif;
}

function page_footer(): void
{
    ?></main>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}
