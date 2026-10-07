<?php
declare(strict_types=1);
const PUBLIC_PAGE = true;
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

// Nøglen står i adressen; den må ikke sendes videre i Referer
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = PasswordReset::complete($token, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if (!$errors) {
        Auth::logout();
        flash('Din adgangskode er gemt. Log ind med den nye adgangskode.');
        redirect('login.php');
    }
}
$u = PasswordReset::find($token);

page_header('Vælg adgangskode');
?>
<form method="post" action="reset.php" class="event-form login-form">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= h($token) ?>">
  <h1><?= !$u ? 'Linket virker ikke' : ($u['purpose'] === 'invite' ? 'Velkommen til Årshjul' : 'Vælg ny adgangskode') ?></h1>

  <?php if (!$u): ?>
    <div class="flash flash-error">Linket er udløbet eller allerede brugt.</div>
    <p><a href="forgot.php">Bed om et nyt link</a></p>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <fieldset>
      <p class="muted">Bruger: <?= h($u['name']) ?> (<?= h($u['email']) ?>)</p>
      <input type="email" name="email" value="<?= h($u['email']) ?>" autocomplete="username" hidden>
      <?php password_fields(); ?>
      <div class="actions">
        <button class="btn primary" type="submit">Gem adgangskode</button>
      </div>
    </fieldset>
  <?php endif; ?>
</form>
<?php page_footer();
