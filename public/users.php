<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_role('admin');

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $me = Auth::user();
    if (isset($_POST['invite'])) {
        // Knappen "Send invitation" ved en bruger: sender kun mailen og gemmer ikke resten af formularen
        $inviteErrors = PasswordReset::invite((int)$_POST['invite'], $me['name']);
        flash($inviteErrors ? implode(' ', $inviteErrors) : 'Invitationen er sendt.', $inviteErrors ? 'error' : 'ok');
        redirect("users.php?year=$year");
    }
    [$newId, $errors] = Users::saveAll($_POST, (int)$me['id']);
    if (!$errors) {
        $inviteErrors = $newId && !empty($_POST['new_invite']) ? PasswordReset::invite($newId, $me['name']) : [];
        if ($inviteErrors) {
            flash('Brugerne er gemt, men invitationen kunne ikke sendes: ' . implode(' ', $inviteErrors), 'error');
        } else {
            flash($newId && !empty($_POST['new_invite']) ? 'Brugerne er gemt, og invitationen er sendt.' : 'Brugerne er gemt.');
        }
        redirect("users.php?year=$year");
    }
}

$users = Users::all();
$people = People::all();
$selfId = (int)Auth::user()['id'];
// Ved fejl vises de indsendte værdier igen
$in = $errors ? $_POST : [];

$roleSelect = function (string $name, string $value): string {
    $html = '<select name="' . h($name) . '">';
    foreach (Auth::ROLES as $role => $label) {
        $html .= '<option value="' . h($role) . '"' . ($role === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
};
$personSelect = function (string $name, ?int $value) use ($people): string {
    $html = '<select name="' . h($name) . '"><option value="">Ingen</option>';
    foreach ($people as $id => $p) {
        $html .= '<option value="' . (int)$id . '"' . ((int)$id === $value ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    }
    return $html . '</select>';
};

page_header('Brugere');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Brugere</h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Brugere</legend>
    <p class="muted">
      <strong>Læser</strong> kan se hjul og liste, åbne filer og eksportere.
      <strong>Bidragyder</strong> kan desuden sætte flueben og skrive noter, links og filer på forekomster.
      <strong>Administrator</strong> kan alt. Kobles en bruger til en person, får brugeren genvejen "Mine begivenheder".
    </p>
    <?php foreach ($users as $id => $u):
      $active = $errors ? !empty($in['active'][$id]) : (bool)$u['active']; ?>
      <div class="row user-row <?= $active ? '' : 'is-inactive' ?>">
        <label class="grow">Navn<input name="name[<?= $id ?>]" required maxlength="150" value="<?= h($in['name'][$id] ?? $u['name']) ?>"></label>
        <label class="grow">Mail<input type="email" name="email[<?= $id ?>]" required maxlength="254" value="<?= h($in['email'][$id] ?? $u['email']) ?>"></label>
        <label>Rolle<?= $roleSelect("role[$id]", $in['role'][$id] ?? $u['role']) ?></label>
        <label>Person<?= $personSelect("person[$id]", (int)($in['person'][$id] ?? $u['person_id']) ?: null) ?></label>
        <label class="inline active"><input type="checkbox" name="active[<?= $id ?>]" value="1" <?= $active ? 'checked' : '' ?> <?= $id === $selfId ? 'disabled' : '' ?>> aktiv</label>
        <?php if ($id === $selfId): ?><input type="hidden" name="active[<?= $id ?>]" value="1"><?php endif; ?>
        <span class="usage muted">
          <?php if ($u['password_hash'] === null): ?>Har ikke valgt adgangskode
            <?php if ($active): ?>
              <button class="btn small" type="submit" name="invite" value="<?= $id ?>" formnovalidate
                title="Sender en mail med et link til at vælge adgangskode (ændringer i formularen gemmes ikke)">Send invitation</button>
            <?php endif; ?>
          <?php elseif ($u['last_login_at']): ?>Sidst logget ind <?= h(date_da($u['last_login_at'], true)) ?>
          <?php else: ?>Har ikke logget ind<?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend>Ny bruger</legend>
    <div class="row">
      <label class="grow">Navn<input name="new_name" maxlength="150" value="<?= h($in['new_name'] ?? '') ?>" placeholder="fx Anne Holm"></label>
      <label class="grow">Mail<input type="email" name="new_email" maxlength="254" value="<?= h($in['new_email'] ?? '') ?>"></label>
      <label>Rolle<?= $roleSelect('new_role', $in['new_role'] ?? 'reader') ?></label>
      <label>Person<?= $personSelect('new_person', (int)($in['new_person'] ?? 0) ?: null) ?></label>
    </div>
    <label class="inline"><input type="checkbox" name="new_invite" value="1" <?= !$errors || !empty($in['new_invite']) ? 'checked' : '' ?>>
      Send invitation på mail, så brugeren kan vælge sin adgangskode (linket virker i <?= PasswordReset::INVITE_DAYS ?> dage)</label>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>
<?php page_footer();
