<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = Categories::saveAll($_POST);
    if (!$errors) {
        flash('Kategorierne er gemt.');
        redirect("categories.php?year=$year");
    }
}

$categories = Categories::all();
// Ved fejl vises de indsendte værdier igen
$in = $errors ? $_POST : [];
$deleted = array_map('intval', (array)($in['delete'] ?? []));

page_header('Kategorier');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Kategorier</h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Kategorier og farver</legend>
    <?php if (!$categories): ?>
      <p class="muted">Der er ingen kategorier endnu.</p>
    <?php endif; ?>
    <?php foreach ($categories as $id => $c): ?>
      <div class="row category-row">
        <label class="grow">Navn<input name="name[<?= $id ?>]" required maxlength="100" value="<?= h($in['name'][$id] ?? $c['name']) ?>"></label>
        <label>Farve<input type="color" name="color[<?= $id ?>]" value="<?= h($in['color'][$id] ?? $c['color']) ?>"></label>
        <span class="usage muted"><?= (int)$c['used'] ?> begivenhed<?= (int)$c['used'] === 1 ? '' : 'er' ?></span>
        <label class="inline delete" <?= $c['used'] ? 'title="Kategorien bruges og kan ikke slettes"' : '' ?>>
          <input type="checkbox" name="delete[]" value="<?= $id ?>" <?= $c['used'] ? 'disabled' : '' ?> <?= in_array($id, $deleted, true) ? 'checked' : '' ?>> slet</label>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend>Ny kategori</legend>
    <div class="row">
      <label class="grow">Navn<input name="new_name" maxlength="100" value="<?= h($in['new_name'] ?? '') ?>" placeholder="fx Økonomi"></label>
      <label>Farve<input type="color" name="new_color" value="<?= h($in['new_color'] ?? '#2f6fde') ?>"></label>
    </div>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>
<?php page_footer();
