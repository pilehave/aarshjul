<?php
declare(strict_types=1);
const PUBLIC_PAGE = true;
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$next = local_path((string)($_GET['next'] ?? $_POST['next'] ?? ''));
if (Auth::user()) {
    redirect($next);
}

$errors = [];
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $email = (string)($_POST['email'] ?? '');
    $errors = Auth::login($email, (string)($_POST['password'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!$errors) {
        redirect($next);
    }
}

try {
    $noUsers = !db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
} catch (PDOException) {
    $noUsers = false; // Tabellen mangler; Auth::login giver en fejlbesked
}

page_header('Log ind');
?>
<form method="post" class="event-form login-form">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <h1>Log ind</h1>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <fieldset>
    <label>Mail<input type="email" name="email" required autocomplete="username" autofocus value="<?= h($email) ?>"></label>
    <label>Adgangskode<input type="password" name="password" required autocomplete="current-password" maxlength="256"></label>
    <div class="actions">
      <button class="btn primary" type="submit">Log ind</button>
    </div>
  </fieldset>

  <?php if ($noUsers): ?>
    <p class="muted">Der er endnu ingen brugere. Opret den første administrator med<br>
      <code>docker compose exec web php bin/create-admin.php</code></p>
  <?php endif; ?>
</form>
<?php page_footer();
