<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_role('admin');

$year = selected_year();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = Settings::saveAll($_POST, $_FILES['logo'] ?? []);
    if (!$errors) {
        flash('Indstillingerne er gemt.');
        redirect("settings.php?year=$year");
    }
}

$startMonth = $errors ? (int)($_POST['start_month'] ?? 1) : Settings::startMonth();
$ringMode = $errors ? (string)($_POST['ring_mode'] ?? 'auto') : Settings::ringMode();
$ringNames = $errors ? !empty($_POST['ring_names']) : Settings::ringNames();
$topbarBg = $errors && Settings::isColor((string)($_POST['topbar_bg'] ?? '')) ? (string)$_POST['topbar_bg'] : Settings::topbarBg();
$topbarFg = $errors && Settings::isColor((string)($_POST['topbar_fg'] ?? '')) ? (string)$_POST['topbar_fg'] : Settings::topbarFg();
$topbarText = $errors ? (string)($_POST['topbar_text'] ?? '') : Settings::topbarText();
// Tomt felt = standardhøjden, så kun en selvvalgt højde vises i feltet
$logoHeight = $errors ? (string)($_POST['topbar_logo_height'] ?? '') : Settings::get('topbar_logo_height');
$logo = Settings::topbarLogo();

page_header('Indstillinger');
?>
<p><a href="index.php?year=<?= $year ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Indstillinger</h1>
<p class="muted">Indstillingerne gælder for hele årshjulet og dermed for alle brugere. Ændrer du fx startmåneden, ringene eller topbaren,
  ser alle andre også ændringen, næste gang de åbner en side.</p>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="event-form" enctype="multipart/form-data">
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

  <fieldset>
    <legend>Design af topbar</legend>
    <div class="row">
      <label>Baggrundsfarve<input type="color" name="topbar_bg" value="<?= h($topbarBg) ?>"></label>
      <label>Tekstfarve<input type="color" name="topbar_fg" value="<?= h($topbarFg) ?>"></label>
      <label class="grow">Tekst<input type="text" name="topbar_text" value="<?= h($topbarText) ?>" maxlength="<?= Settings::TOPBAR_TEXT_MAX ?>"
        placeholder="<?= h(Settings::DEFAULTS['topbar_text']) ?>"></label>
    </div>
    <?php $topbarContrast = Wheel::contrast($topbarBg, $topbarFg); if ($topbarContrast < 4.5): ?>
      <div class="flash flash-error">Tekstfarven har kun en kontrast på <?= h(number_format($topbarContrast, 1, ',', '')) ?>:1 mod baggrunden.
        Vælg farver med mindst 4,5:1, så teksten i topbaren er let at læse for alle.</div>
    <?php endif; ?>
    <p class="muted">Standard er rød baggrund (<?= Settings::DEFAULTS['topbar_bg'] ?>) med hvid tekst og teksten "<?= h(Settings::DEFAULTS['topbar_text']) ?>".
      Teksten vises i topbaren og fanebladet, men ikke i midten af hjulet. Den må være tom, hvis der er et logo.</p>
    <div class="row">
      <label class="grow">Logo<input type="file" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp,.svg,<?= implode(',', Settings::LOGO_TYPES) ?>"></label>
      <label>Logoets højde (px)<input type="number" name="topbar_logo_height" value="<?= h($logoHeight) ?>"
        min="<?= Settings::LOGO_HEIGHT_MIN ?>" max="<?= Settings::LOGO_HEIGHT_MAX ?>" step="1" placeholder="<?= Settings::LOGO_HEIGHT_DEFAULT ?>"></label>
    </div>
    <p class="muted">PNG, JPG, GIF, WebP eller SVG på højst <?= Settings::LOGO_MAX_BYTES / 1024 / 1024 ?> MB. Logoet vises til venstre for teksten
      og skaleres til den valgte højde (<?= Settings::LOGO_HEIGHT_MIN ?>–<?= Settings::LOGO_HEIGHT_MAX ?> pixels). Uden en højde bruges
      <?= Settings::LOGO_HEIGHT_DEFAULT ?> pixels. Et nyt logo erstatter det nuværende.</p>
    <?php if ($logo): ?>
      <div class="row">
        <span class="logo-preview" style="background: <?= h(Settings::topbarBg()) ?>; --logo-h: <?= Settings::logoHeight() ?>px"><img src="logo.php?v=<?= h($logo['v']) ?>" alt="Nuværende logo"></span>
        <label><input type="checkbox" name="remove_logo" value="1" <?= !empty($_POST['remove_logo']) ? 'checked' : '' ?>> Fjern logoet</label>
      </div>
    <?php endif; ?>
  </fieldset>

  <div class="actions">
    <button class="btn primary" type="submit">Gem</button>
    <a class="btn" href="index.php?year=<?= $year ?>">Annullér</a>
  </div>
</form>
<?php page_footer();
