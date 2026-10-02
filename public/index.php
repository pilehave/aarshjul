<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
$occ = Events::occurrencesForYear($year, $personId);
$people = Events::people();
$q = fn(array $p) => '?' . http_build_query(array_filter(['year' => $year, 'person' => $personId] + $p));

page_header("Årshjul $year");
?>
<div class="toolbar">
  <div class="yearnav">
    <a class="btn" href="<?= h($q(['year' => $year - 1])) ?>">‹ <?= $year - 1 ?></a>
    <strong><?= $year ?></strong>
    <a class="btn" href="<?= h($q(['year' => $year + 1])) ?>"><?= $year + 1 ?> ›</a>
  </div>
  <form method="get" class="filter">
    <input type="hidden" name="year" value="<?= $year ?>">
    <label>Person
      <select name="person" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $personId === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="spacer"></div>
  <a class="btn primary" href="event.php?year=<?= $year ?>">+ Ny begivenhed</a>
  <div class="export">
    <span>Eksportér:</span>
    <a class="btn" href="export_xlsx.php<?= h($q([])) ?>">Excel</a>
    <button class="btn" type="button" data-export="png">PNG</button>
    <button class="btn" type="button" data-export="pdf">PDF</button>
    <a class="btn" href="wheel_svg.php<?= h($q(['download' => 1])) ?>">SVG</a>
  </div>
</div>

<div class="layout">
  <section class="wheel" id="wheel" data-year="<?= $year ?>">
    <?= Wheel::svg($year, $occ) ?>
    <p class="legend">
      <span class="lg done"></span> Opfyldt
      <span class="lg blocked"></span> Venter på forudsætning
      <span class="lg today"></span> I dag
    </p>
  </section>

  <section class="list" id="event-list">
    <?php if (!$occ): ?>
      <p class="muted">Ingen begivenheder i <?= $year ?>. <a href="event.php?year=<?= $year ?>">Opret den første</a>.</p>
    <?php endif; ?>
    <?php $curMonth = 0; foreach ($occ as $o):
      $ev = $o['event'];
      $m = (int)substr($o['date'], 5, 2);
      if ($m !== $curMonth): $curMonth = $m; ?>
        <h2 class="month"><?= ucfirst(MONTHS_DA[$m]) ?></h2>
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
          <div class="occ-meta">
            <?= h(date_da($o['date'])) ?><?= $o['end'] !== $o['date'] ? ' – ' . h(date_da($o['end'], substr($o['end'], 0, 4) !== substr($o['date'], 0, 4))) : '' ?>
            · <?= h(Recurrence::describe($ev)) ?>
          </div>
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
