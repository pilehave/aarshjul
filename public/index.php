<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$occ = Events::occurrencesForYear($year, $personId, $categoryId);
$people = Events::people();
$categories = Categories::all();
$label = year_label($year);
$crossesYear = Settings::startMonth() !== 1;
$q = fn(array $p) => '?' . http_build_query(array_filter(['year' => $year, 'person' => $personId, 'category' => $categoryId] + $p));

page_header("Årshjul $label");
?>
<div class="toolbar">
 <div class="toolbar-row">
  <div class="yearnav">
    <a class="btn" href="<?= h($q(['year' => $year - 1])) ?>">‹ <?= h(year_label($year - 1)) ?></a>
    <strong><?= h($label) ?></strong>
    <a class="btn" href="<?= h($q(['year' => $year + 1])) ?>"><?= h(year_label($year + 1)) ?> ›</a>
  </div>
  <div class="spacer"></div>
  <a class="btn primary" href="event.php?year=<?= $year ?>">+ Ny begivenhed</a>
  <a class="btn" href="categories.php?year=<?= $year ?>">Kategorier</a>
  <a class="btn" href="people.php?year=<?= $year ?>">Personer</a>
  <a class="btn icon" href="settings.php?year=<?= $year ?>" title="Indstillinger" aria-label="Indstillinger">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
  </a>
 </div>
 <div class="toolbar-row">
  <form method="get" class="filter">
    <input type="hidden" name="year" value="<?= $year ?>">
    <label><span class="field-label">Person</span>
      <select name="person" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $personId === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span class="field-label">Kategori</span>
      <select name="category" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach ($categories as $id => $c): ?>
          <option value="<?= (int)$id ?>" <?= $categoryId === (int)$id ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <button class="btn" type="button" id="toggle-list" aria-controls="event-list" aria-expanded="true">Skjul begivenheder</button>
  <div class="spacer"></div>
  <div class="export">
    <span class="field-label">Eksportér</span>
    <div class="btn-group">
      <a class="btn" href="export_xlsx.php<?= h($q([])) ?>">Excel</a>
      <button class="btn" type="button" data-export="png">PNG</button>
      <button class="btn" type="button" data-export="pdf">PDF</button>
      <a class="btn" href="wheel_svg.php<?= h($q(['download' => 1])) ?>">SVG</a>
    </div>
  </div>
 </div>
</div>

<script>
  // Sæt klassen før siden tegnes, så listen ikke blinker frem, når den er skjult
  try { if (localStorage.getItem('aarshjul.listHidden') === '1') document.documentElement.classList.add('list-hidden'); } catch (e) {}
</script>
<div class="layout">
  <section class="wheel" id="wheel" data-year="<?= h($label) ?>">
    <?= Wheel::svg($year, $occ) ?>
    <p class="legend">
      <span class="lg done"></span> Opfyldt
      <span class="lg blocked"></span> Venter på forudsætning
      <span class="lg today"></span> I dag
    </p>
  </section>

  <section class="list" id="event-list">
    <?php if (!$occ): ?>
      <p class="muted">Ingen begivenheder i <?= h($label) ?>. <a href="event.php?year=<?= $year ?>">Opret den første</a>.</p>
    <?php endif; ?>
    <?php $curMonth = ''; foreach ($occ as $o):
      $ev = $o['event'];
      $m = substr($o['date'], 0, 7);
      if ($m !== $curMonth): $curMonth = $m; ?>
        <h2 class="month"><?= ucfirst(MONTHS_DA[(int)substr($m, 5)]) ?><?= $crossesYear ? ' ' . substr($m, 0, 4) : '' ?></h2>
      <?php endif; ?>
      <article class="occ <?= $o['done'] ? 'is-done' : '' ?> <?= $o['blocked'] ? 'is-blocked' : '' ?>" style="--c: <?= h($ev['color']) ?>">
        <form method="post" action="action.php" class="check">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
          <input type="hidden" name="date" value="<?= h($o['date']) ?>">
          <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
          <input type="checkbox" name="done" value="1" title="<?= $o['blocked'] && !$o['done'] ? 'Kan først krydses af, når forudsætningerne er opfyldt' : 'Marker som opfyldt' ?>"
            <?= $o['done'] ? 'checked' : '' ?> <?= $o['blocked'] && !$o['done'] ? 'disabled' : '' ?> onchange="this.form.submit()">
        </form>
        <div class="occ-body">
          <a class="occ-title" href="event.php?id=<?= (int)$ev['id'] ?>&amp;year=<?= $year ?>"><?= h($ev['title']) ?></a>
          <span class="occ-cat"><?= h($ev['category_name']) ?></span>
          <div class="occ-meta">
            <?= h(date_da($o['date'])) ?><?= $o['end'] !== $o['date'] ? ' – ' . h(date_da($o['end'], substr($o['end'], 0, 4) !== substr($o['date'], 0, 4))) : '' ?>
            · <?= h(Recurrence::describe($ev)) ?>
          </div>
          <?php if (trim((string)$ev['description']) !== ''): ?>
            <div class="occ-desc"><?= h(trim($ev['description'])) ?></div>
            <button type="button" class="occ-more" hidden>Vis mere</button>
          <?php endif; ?>
          <?php if ($ev['people']): ?>
            <div class="chips"><?php foreach ($ev['people'] as $p): ?><span class="chip"><?= h($p['name']) ?></span><?php endforeach; ?></div>
          <?php endif; ?>
          <?php foreach ($o['prereqs'] as $p): ?>
            <div class="prereq <?= $p['done'] ? 'ok' : 'wait' ?>">
              <?= $p['done'] ? '✓' : '⏳' ?> Afhænger af <a href="event.php?id=<?= (int)$p['event']['id'] ?>&amp;year=<?= $year ?>"><?= h($p['event']['title']) ?></a>
              <?= $p['date'] ? '(' . h(date_da($p['date'], true)) . ')' : '(ingen forekomst)' ?>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
</div>
<?php page_footer();
