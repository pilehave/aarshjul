<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = Auth::changePassword((string)($_POST['current'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if (!$errors) {
        flash('Din adgangskode er skiftet. Du er logget ud alle andre steder.');
        redirect("account.php?year=$year");
    }
}
$u = Auth::user();

page_header('Min konto');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Min konto</h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Oplysninger</legend>
    <dl class="modal-facts">
      <dt>Navn</dt><dd><?= h($u['name']) ?></dd>
      <dt>Mail</dt><dd><?= h($u['email']) ?></dd>
      <dt>Rolle</dt><dd><?= h(Auth::ROLES[$u['role']]) ?></dd>
    </dl>
    <p class="muted">Navn, mail og rolle ændres af en administrator.</p>
  </fieldset>
  <fieldset>
    <legend>Skift adgangskode</legend>
    <input type="email" name="email" value="<?= h($u['email']) ?>" autocomplete="username" hidden>
    <label>Nuværende adgangskode<input type="password" name="current" required autocomplete="current-password" maxlength="256"></label>
    <?php password_fields(); ?>
    <div class="actions">
      <button class="btn primary" type="submit">Skift adgangskode</button>
    </div>
  </fieldset>
</form>
<?php page_footer();
