<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = People::saveAll($_POST);
    if (!$errors) {
        flash('Personerne er gemt.');
        redirect("people.php?year=$year");
    }
}

$people = People::all();
// Ved fejl vises de indsendte værdier igen
$in = $errors ? $_POST : [];
$deleted = array_map('intval', (array)($in['delete'] ?? []));

page_header('Personer');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= $year ?></a></p>
<h1>Personer</h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form" onsubmit="return !this.querySelector('[name=&quot;delete[]&quot;]:checked') || confirm('Slet de markerede personer? De fjernes også fra deres begivenheder.')">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Personer</legend>
    <?php if (!$people): ?>
      <p class="muted">Der er ingen personer endnu.</p>
    <?php endif; ?>
    <?php foreach ($people as $id => $p): ?>
      <div class="row category-row">
        <label class="grow">Navn<input name="name[<?= $id ?>]" required maxlength="150" value="<?= h($in['name'][$id] ?? $p['name']) ?>"></label>
        <span class="usage muted"><?= (int)$p['used'] ?> begivenhed<?= (int)$p['used'] === 1 ? '' : 'er' ?></span>
        <label class="inline delete"><input type="checkbox" name="delete[]" value="<?= $id ?>" <?= in_array($id, $deleted, true) ? 'checked' : '' ?>> slet</label>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend>Ny person</legend>
    <label>Navn<input name="new_name" maxlength="150" value="<?= h($in['new_name'] ?? '') ?>" placeholder="fx Anne Holm"></label>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>
<?php page_footer();
