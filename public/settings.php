<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_role('admin');

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = Settings::saveAll($_POST);
    if (!$errors) {
        flash('Indstillingerne er gemt.');
        redirect("settings.php?year=$year");
    }
}

$startMonth = $errors ? (int)($_POST['start_month'] ?? 1) : Settings::startMonth();
$ringMode = $errors ? (string)($_POST['ring_mode'] ?? 'auto') : Settings::ringMode();
$ringNames = $errors ? !empty($_POST['ring_names']) : Settings::ringNames();

page_header('Indstillinger');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Indstillinger</h1>

<?php if ($errors): ?>
  <div class="flash flash-error"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Årshjulet</legend>
    <div class="row">
      <label>Startmåned
        <select name="start_month">
          <?php foreach (MONTHS_DA as $m => $name): ?>
            <option value="<?= $m ?>" <?= $m === $startMonth ? 'selected' : '' ?>><?= ucfirst($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="muted">Den måned, årshjulet starter med øverst. Starter det fx i august, viser hjulet for 2026
      perioden 1. august 2026 – 31. juli 2027.</p>
  </fieldset>

  <fieldset>
    <legend>Ringe i hjulet</legend>
    <div class="row">
      <label>Fordeling
        <select name="ring_mode" id="ring_mode">
          <?php foreach (Settings::RING_MODES as $mode => $name): ?>
            <option value="<?= h($mode) ?>" <?= $mode === $ringMode ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="muted"><strong>Automatisk</strong> lægger begivenhederne, hvor der er plads. Med <strong>én ring pr. kategori</strong>
      eller <strong>pr. person</strong> har hver ring en betydning, og ringens navn står i et tyndt bånd yderst i ringen.
      Kategorier og personer uden begivenheder i året får ingen ring. En begivenhed med flere personer vises i hver af deres ringe,
      og begivenheder uden personer samles i ringen "Ingen person".</p>
    <label><input type="checkbox" name="ring_names" value="1" <?= $ringNames ? 'checked' : '' ?>>
      Vis kategorinavne (kun ved én ring pr. kategori)</label>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>
<?php page_footer();
