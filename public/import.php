<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_role('admin');

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $file = $_FILES['template'] ?? null;
    $shift = ($_POST['shift'] ?? '') === 'year';
    $toYear = max(1970, min(2200, (int)($_POST['to_year'] ?? $year)));
    if (!$file || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Vælg en skabelon (.json).';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload af filen fejlede (kode ' . (int)$file['error'] . ').';
    } elseif ($file['size'] > Template::MAX_BYTES) {
        $errors[] = 'Filen er større end ' . (Template::MAX_BYTES / 1024 / 1024) . ' MB.';
    } else {
        [$tpl, $errors] = Template::parse((string)file_get_contents($file['tmp_name']));
        if ($tpl) {
            [$stats, $errors] = Template::import($tpl, $shift ? Template::yearShift($tpl, $toYear) : 0);
            if (!$errors) {
                flash(sprintf('Skabelonen er importeret: %d begivenhed%s, %d ny%s kategori%s og %d ny%s person%s.',
                    $stats['events'], $stats['events'] === 1 ? '' : 'er',
                    $stats['categories'], $stats['categories'] === 1 ? '' : 'e', $stats['categories'] === 1 ? '' : 'er',
                    $stats['people'], $stats['people'] === 1 ? '' : 'e', $stats['people'] === 1 ? '' : 'er'));
                redirect('index.php?year=' . ($shift ? $toYear : $year));
            }
        }
    }
}

$categories = Categories::all();
$people = People::all();
$shiftYear = $errors && ($_POST['shift'] ?? '') === 'year';

page_header('Skabelon');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Skabelon</h1>
<p class="muted">En skabelon er begivenhederne med kategorier, personer, links og afhængigheder i en JSON-fil. Den kan importeres
  i et andet årshjul eller bruges som backup. Filer, flueben og noter kommer ikke med. Gentagne begivenheder fortsætter af sig selv,
  så der er ikke brug for at kopiere et år til det næste.</p>

<?php if ($errors): ?>
  <div class="flash flash-error"><p>Skabelonen blev ikke importeret, og intet er ændret:</p>
    <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="get" action="export_json.php" class="event-form">
  <fieldset>
    <legend>Eksportér</legend>
    <p class="muted">Uden filter kommer alle begivenheder, kategorier og personer med. Afhængigheder af begivenheder, der ikke kommer med, udelades.</p>
    <div class="row">
      <label>Kategori
        <select name="category">
          <option value="">Alle kategorier</option>
          <?php foreach ($categories as $id => $c): ?>
            <option value="<?= (int)$id ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Person
        <select name="person">
          <option value="">Alle personer</option>
          <?php foreach ($people as $id => $p): ?>
            <option value="<?= (int)$id ?>"><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="actions">
      <button class="btn primary" type="submit">Eksportér skabelon (.json)</button>
    </div>
  </fieldset>
</form>

<form method="post" enctype="multipart/form-data" class="event-form">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Importér</legend>
    <p class="muted">Begivenhederne tilføjes til dem, der allerede er her. Kategorier og personer med samme navn genbruges, resten oprettes.
      Er der fejl i filen, importeres intet.</p>
    <label>Skabelon <small>(.json, max <?= Template::MAX_BYTES / 1024 / 1024 ?> MB)</small><input type="file" name="template" accept=".json,application/json" required></label>
    <label class="inline"><input type="radio" name="shift" value="none" <?= $shiftYear ? '' : 'checked' ?>> Behold datoerne fra skabelonen</label>
    <label class="inline"><input type="radio" name="shift" value="year" <?= $shiftYear ? 'checked' : '' ?>> Flyt datoerne, så den første begivenhed starter i årshjulet
      <select name="to_year" aria-label="Årshjul">
        <?php $sel = $shiftYear ? (int)$_POST['to_year'] : $year; for ($y = $year - 3; $y <= $year + 5; $y++): ?>
          <option value="<?= $y ?>" <?= $y === $sel ? 'selected' : '' ?>><?= h(year_label($y)) ?></option>
        <?php endfor; ?>
      </select></label>
    <p class="muted">Datoerne flyttes med hele år. Ugentlige gentagelser beholder deres ugedag.</p>
    <div class="actions">
      <button class="btn primary" type="submit">Importér</button>
    </div>
  </fieldset>
</form>
<?php page_footer();
