<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0) ?: null;
$all = Events::all();
$errors = [];

if ($id && !isset($all[$id])) {
    http_response_code(404);
    exit('Begivenheden findes ikke.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (($_POST['do'] ?? '') === 'delete' && $id) {
        Events::delete($id);
        flash('Begivenheden "' . $all[$id]['title'] . '" er slettet.');
        redirect("index.php?year=$year");
    }
    [$savedId, $errors] = Events::save($id, $_POST, $_FILES['files'] ?? []);
    if (!$errors) {
        flash('Begivenheden er gemt.');
        redirect("event.php?id=$savedId&year=$year");
    }
}

// Formularværdier: indsendte værdier ved fejl, ellers fra databasen, ellers standard
$e = $id ? $all[$id] : [
    'title' => '', 'description' => '', 'color' => '#2f6fde', 'start_date' => "$year-01-01",
    'end_date' => '', 'duration_days' => 1, 'recurrence' => 'none', 'rec_interval' => 1,
    'rule_month' => null, 'rule_weekday' => 1, 'rule_nth' => 1, 'people' => [], 'links' => [], 'files' => [], 'depends_on' => [],
];
if ($errors) {
    $p = $_POST;
    $e = array_merge($e, array_intersect_key($p, array_flip(['title', 'description', 'color', 'start_date', 'end_date',
        'duration_days', 'recurrence', 'rec_interval', 'rule_month', 'rule_weekday'])));
    $e['rule_nth'] = ($p['recurrence'] ?? '') === 'week_number' ? ($p['week_number'] ?? '') : ($p['rule_nth'] ?? 1);
    $e['people'] = array_map(fn($n) => ['name' => trim($n)], array_filter(preg_split('/[,\n;]+/', (string)($p['people'] ?? ''))));
    $e['links'] = array_map(fn($u, $l) => ['url' => $u, 'label' => $l], (array)($p['link_url'] ?? []), (array)($p['link_label'] ?? []));
    $e['depends_on'] = array_map('intval', (array)($p['depends_on'] ?? []));
}
$peopleTxt = implode(', ', array_column($e['people'], 'name'));
$links = $e['links'] ?: [['url' => '', 'label' => '']];
$nextDates = $id ? Recurrence::occurrences($all[$id], date('Y-m-d'), (new DateTimeImmutable('+3 years'))->format('Y-m-d')) : [];
$dependents = $id ? array_filter($all, fn($x) => in_array($id, $x['depends_on'], true)) : [];

page_header($id ? $e['title'] : 'Ny begivenhed');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= $year ?></a></p>
<h1><?= $id ? 'Rediger: ' . h($e['title']) : 'Ny begivenhed' ?></h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="event-form">
  <?= csrf_field() ?>
  <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

  <fieldset>
    <legend>Begivenhed</legend>
    <div class="row">
      <label class="grow">Titel *<input name="title" required maxlength="200" value="<?= h($e['title']) ?>"></label>
      <label>Farve<input type="color" name="color" value="<?= h($e['color']) ?>"></label>
    </div>
    <label>Beskrivelse<textarea name="description" rows="4"><?= h($e['description']) ?></textarea></label>
    <label>Personer <small>(adskil med komma)</small>
      <input name="people" list="people-list" value="<?= h($peopleTxt) ?>" placeholder="fx Anne Holm, Jonas Berg">
    </label>
    <datalist id="people-list"><?php foreach (Events::people() as $p): ?><option value="<?= h($p['name']) ?>"><?php endforeach; ?></datalist>
  </fieldset>

  <fieldset>
    <legend>Dato og gentagelse</legend>
    <div class="row">
      <label>Startdato *<input type="date" name="start_date" required value="<?= h($e['start_date']) ?>"></label>
      <label>Varighed (dage)<input type="number" name="duration_days" min="1" max="366" value="<?= (int)$e['duration_days'] ?>"></label>
      <label class="grow">Gentagelse
        <select name="recurrence" id="recurrence">
          <?php foreach (Recurrence::RULES as $k => $label): ?>
            <option value="<?= $k ?>" <?= $e['recurrence'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="row rule" data-rules="weekly monthly yearly">
      <label>Hver<input type="number" name="rec_interval" min="1" max="99" value="<?= (int)$e['rec_interval'] ?>"></label>
      <span class="hint" data-rules="weekly">. uge, på startdatoens ugedag</span>
      <span class="hint" data-rules="monthly">. måned, på startdatoens dag</span>
      <span class="hint" data-rules="yearly">. år, på startdatoens dato</span>
    </div>
    <div class="row rule" data-rules="nth_weekday">
      <label>Den
        <select name="rule_nth">
          <?php foreach ([1 => '1.', 2 => '2.', 3 => '3.', 4 => '4.', -1 => 'sidste'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= (int)$e['rule_nth'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="row rule" data-rules="nth_weekday week_number">
      <label>Ugedag
        <select name="rule_weekday">
          <?php foreach (WEEKDAYS_DA as $k => $v): ?>
            <option value="<?= $k ?>" <?= (int)($e['rule_weekday'] ?? 1) === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="row rule" data-rules="nth_weekday last_week">
      <label>I måneden
        <select name="rule_month">
          <option value="">Hver måned</option>
          <?php foreach (MONTHS_DA as $k => $v): ?>
            <option value="<?= $k ?>" <?= (int)$e['rule_month'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <span class="hint" data-rules="last_week">Sidste uge = ugen der starter med månedens sidste mandag. Sæt varighed til 5 for man–fre eller 7 for hele ugen.</span>
    </div>
    <div class="row rule" data-rules="week_number">
      <label>Ugenummer<input type="number" name="week_number" min="1" max="53" value="<?= $e['recurrence'] === 'week_number' ? (int)$e['rule_nth'] : '' ?>"></label>
    </div>
    <div class="row rule" data-rules="weekly monthly yearly nth_weekday last_week week_number">
      <label>Gentag til og med <small>(valgfri)</small><input type="date" name="end_date" value="<?= h($e['end_date'] ?? '') ?>"></label>
      <span class="hint">Startdatoen er den første dato, gentagelsen kan falde på.</span>
    </div>
    <?php if ($nextDates): ?>
      <p class="muted">Næste forekomster: <?= h(implode(', ', array_map(fn($d) => date_da($d, true), array_slice($nextDates, 0, 6)))) ?></p>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Afhængigheder</legend>
    <p class="muted">Begivenheden kan først krydses af som opfyldt, når de valgte begivenheder er krydset af.</p>
    <div class="deps">
      <?php foreach ($all as $other): if ($other['id'] == $id) continue; ?>
        <label class="dep"><input type="checkbox" name="depends_on[]" value="<?= (int)$other['id'] ?>" <?= in_array((int)$other['id'], $e['depends_on'], true) ? 'checked' : '' ?>>
          <span class="dot" style="background: <?= h($other['color']) ?>"></span><?= h($other['title']) ?></label>
      <?php endforeach; ?>
    </div>
    <?php if ($dependents): ?>
      <p class="muted">Disse afhænger af denne begivenhed: <?= h(implode(', ', array_column($dependents, 'title'))) ?></p>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Links</legend>
    <div id="links">
      <?php foreach ($links as $l): ?>
        <div class="row link-row">
          <label class="grow">URL<input type="url" name="link_url[]" value="<?= h($l['url']) ?>" placeholder="https://"></label>
          <label class="grow">Tekst<input name="link_label[]" value="<?= h($l['label'] ?? '') ?>"></label>
          <button type="button" class="btn small" data-remove-row>✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn small" id="add-link">+ Tilføj link</button>
    <?php if ($id && $all[$id]['links']): ?>
      <ul class="plain"><?php foreach ($all[$id]['links'] as $l): ?><li><a href="<?= h($l['url']) ?>" target="_blank" rel="noopener"><?= h($l['label'] ?: $l['url']) ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>Filer</legend>
    <?php if ($id && $all[$id]['files']): ?>
      <ul class="plain files">
        <?php foreach ($all[$id]['files'] as $f): ?>
          <li><a href="download.php?id=<?= (int)$f['id'] ?>"><?= h($f['original_name']) ?></a>
            <small>(<?= number_format($f['size_bytes'] / 1024, 0, ',', '.') ?> KB)</small>
            <label class="inline"><input type="checkbox" name="delete_file[]" value="<?= (int)$f['id'] ?>"> slet</label></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <label>Upload filer <small>(max <?= (int)config('max_upload_mb') ?> MB pr. fil)</small><input type="file" name="files[]" multiple></label>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>

<?php if ($id): ?>
  <form method="post" class="delete-form" onsubmit="return confirm('Slet begivenheden og alle dens filer, links og flueben?')">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="do" value="delete">
    <button class="btn danger" type="submit">Slet begivenhed</button>
  </form>
<?php endif; ?>
<?php page_footer();
