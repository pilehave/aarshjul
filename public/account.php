<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ical') {
    // Opret eller udskift kalenderadressen. Forsiden sender back med #kalender, så dialogen åbnes igen
    require_post_csrf();
    $had = Ical::token((int)Auth::user()['id']) !== null;
    Ical::renewToken((int)Auth::user()['id']);
    flash($had ? 'Du har fået en ny kalenderadresse. Den gamle virker ikke længere, så husk at opdatere dine abonnementer.'
        : 'Din kalenderadresse er oprettet.');
    redirect(local_path((string)($_POST['back'] ?? ''), "account.php?year=$year#kalender"));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = Auth::changePassword((string)($_POST['current'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if (!$errors) {
        flash('Din adgangskode er skiftet. Du er logget ud alle andre steder.');
        redirect("account.php?year=$year");
    }
}
$u = Auth::user();
$icalToken = Ical::token((int)$u['id']);

page_header('Min konto');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Min konto</h1>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
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
    <label>Nuværende adgangskode *<input type="password" name="current" required autocomplete="current-password" maxlength="256"></label>
    <?php password_fields(); ?>
    <div class="actions">
      <button class="btn primary" type="submit">Skift adgangskode</button>
    </div>
  </fieldset>
</form>

<form method="post" class="event-form" id="kalender">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="ical">
  <fieldset>
    <legend>Kalenderabonnement</legend>
    <?php if ($icalToken): ?>
      <?php ical_address(Ical::url($icalToken, name: Ical::name())); ?>
      <p class="muted">Adressen viser alle begivenheder. På forsiden kan du under "Abonnér i kalender" få en adresse,
        der kun viser én person eller kategori. Laver du en ny adresse, holder den gamle op med at virke, også i de kalendere,
        der abonnerer på den.</p>
      <div class="actions">
        <button class="btn danger" type="submit" onclick="return confirm('Den gamle adresse holder op med at virke. Fortsæt?')">Lav ny adresse</button>
      </div>
    <?php else: ?>
      <p>Abonnér på årshjulet i Outlook, Google Kalender eller Apple Kalender, så begivenhederne vises i din egen kalender.</p>
      <div class="actions">
        <button class="btn primary" type="submit">Opret kalenderadresse</button>
      </div>
    <?php endif; ?>
  </fieldset>
</form>
<?php page_footer();
