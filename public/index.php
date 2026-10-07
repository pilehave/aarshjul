<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$onlyMissing = !empty($_GET['missing']);
$all = Events::all();
$occ = Events::occurrencesForYear($year, $personId, $categoryId, $all, $onlyMissing);
$occ = array_map(fn(array $o) => $o + ['dependents' => Events::dependents($o['event'], $o['date'], $all)], $occ);
$people = Events::people();
$categories = Categories::all();
$label = year_label($year);
$crossesYear = Settings::startMonth() !== 1;
$isAdmin = Auth::can('admin');          // må redigere begivenheder, kategorier, personer, indstillinger og brugere
$canEdit = Auth::can('contributor');    // må sætte flueben og skrive noter på forekomster
$myPersonId = (int)Auth::user()['person_id'] ?: null;
$myId = (int)Auth::user()['id'];
// En bidragyder må kun slette sine egne filer på en forekomst (håndhæves i Events::saveOccurrence)
$canDeleteFile = fn(array $f) => $isAdmin || ($canEdit && (int)$f['uploaded_by'] === $myId);
/** Begivenhedens titel, som link til redigering for administratorer */
function event_link(array $event, int $year, bool $isAdmin): string
{
    return $isAdmin ? '<a href="event.php?id=' . (int)$event['id'] . '&amp;year=' . $year . '">' . h($event['title']) . '</a>' : h($event['title']);
}
// Detaljer til modalen, der åbnes ved klik på en begivenhed i hjulet (nøglet som $occ, se data-occ i Wheel)
$details = array_map(fn(array $o) => [
    'id'          => (int)$o['event']['id'],
    'date'        => $o['date'],
    'title'       => $o['event']['title'],
    'category'    => $o['event']['category_name'],
    'color'       => $o['event']['color'],
    'period'      => $o['end'] === $o['date'] ? date_da($o['date'], true)
        : date_da($o['date'], substr($o['date'], 0, 4) !== substr($o['end'], 0, 4)) . ' – ' . date_da($o['end'], true),
    'recurrence'  => Recurrence::describe($o['event']),
    'description' => trim((string)$o['event']['description']),
    'people'      => array_column($o['event']['people'], 'name'),
    'dependents'  => array_map(fn($d) => [
        'title' => $d['event']['title'],
        'date'  => $d['date'] ? date_da($d['date'], true) : null,
    ], $o['dependents']),
    'prereqs'     => array_map(fn($p) => [
        'title' => $p['event']['title'],
        'date'  => $p['date'] ? date_da($p['date'], true) : null,
        'done'  => $p['done'],
    ], $o['prereqs']),
    'done'        => $o['done'],
    'completed'   => $o['completed'] ? Events::completedText($o['completed']) : null,
    'overdue'     => $o['overdue'],
    'note'        => (string)$o['note'],
    'note_by'     => Events::noteText($o['note_at'], $o['note_by']),
    'links'       => array_map(fn($l) => ['url' => $l['url'], 'label' => (string)$l['label'], 'by' => $l['by_name']], $o['links']),
    'files'       => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['original_name'], 'kb' => (int)ceil($f['size_bytes'] / 1024),
        'by' => Events::uploadedText($f), 'delete' => $canDeleteFile($f)], $o['files']),
    'blocked'     => $o['blocked'],
    'edit'        => $isAdmin ? 'event.php?id=' . (int)$o['event']['id'] . '&year=' . $year : null,
], $occ);
// Nuværende filtre med $p som ændringer (null fjerner en parameter)
$q = fn(array $p) => '?' . http_build_query(array_filter($p + ['year' => $year, 'person' => $personId, 'category' => $categoryId, 'missing' => $onlyMissing ? 1 : null]));

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
  <?php if ($isAdmin): ?>
  <a class="btn primary" href="event.php?year=<?= $year ?>">+ Ny begivenhed</a>
  <a class="btn" href="categories.php?year=<?= $year ?>">Kategorier</a>
  <a class="btn" href="people.php?year=<?= $year ?>">Personer</a>
  <a class="btn" href="users.php?year=<?= $year ?>">Brugere</a>
  <a class="btn icon" href="settings.php?year=<?= $year ?>" title="Indstillinger" aria-label="Indstillinger">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
  </a>
  <?php endif; ?>
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
    <?php if ($myPersonId): ?>
      <a class="btn <?= $personId === $myPersonId ? 'primary' : '' ?>" href="<?= h($q(['person' => $personId === $myPersonId ? null : $myPersonId])) ?>"
        title="Vis kun begivenheder, du er tilknyttet">Mine begivenheder</a>
    <?php endif; ?>
    <label><span class="field-label">Kategori</span>
      <select name="category" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach ($categories as $id => $c): ?>
          <option value="<?= (int)$id ?>" <?= $categoryId === (int)$id ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label title="Vis kun forekomster uden flueben, herunder de overskredne">
      <input type="checkbox" name="missing" value="1" <?= $onlyMissing ? 'checked' : '' ?> onchange="this.form.submit()"> Kun ikke-opfyldte
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
      <span class="lg overdue"></span> Overskredet
      <span class="lg note"></span> Note, link eller vedhæftning
      <span class="lg today"></span> I dag
    </p>
  </section>

  <section class="list" id="event-list">
    <?php if (!$occ): ?>
      <p class="muted">Ingen begivenheder i <?= h($label) ?>.<?php if ($isAdmin): ?> <a href="event.php?year=<?= $year ?>">Opret den første</a>.<?php endif; ?></p>
    <?php endif; ?>
    <?php $curMonth = ''; foreach ($occ as $o):
      $ev = $o['event'];
      $m = substr($o['date'], 0, 7);
      if ($m !== $curMonth): $curMonth = $m; ?>
        <h2 class="month"><?= ucfirst(MONTHS_DA[(int)substr($m, 5)]) ?><?= $crossesYear ? ' ' . substr($m, 0, 4) : '' ?></h2>
      <?php endif; ?>
      <article class="occ <?= $o['done'] ? 'is-done' : '' ?> <?= $o['blocked'] ? 'is-blocked' : '' ?> <?= $o['overdue'] ? 'is-overdue' : '' ?>" style="--c: <?= h($ev['color']) ?>">
        <form method="post" action="action.php" class="check">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
          <input type="hidden" name="date" value="<?= h($o['date']) ?>">
          <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
          <input type="checkbox" name="done" value="1" title="<?= !$canEdit ? ($o['done'] ? 'Opfyldt' : 'Ikke opfyldt') : ($o['blocked'] && !$o['done'] ? 'Kan først krydses af, når forudsætningerne er opfyldt' : 'Marker som opfyldt') ?>"
            <?= $o['done'] ? 'checked' : '' ?> <?= !$canEdit || ($o['blocked'] && !$o['done']) ? 'disabled' : '' ?> onchange="this.form.submit()">
        </form>
        <div class="occ-body">
          <?php if ($isAdmin): ?>
            <a class="occ-title" href="event.php?id=<?= (int)$ev['id'] ?>&amp;year=<?= $year ?>"><?= h($ev['title']) ?></a>
          <?php else: ?>
            <span class="occ-title"><?= h($ev['title']) ?></span>
          <?php endif; ?>
          <span class="occ-cat"><?= h($ev['category_name']) ?></span>
          <?php if ($o['overdue']): ?><span class="occ-overdue">Overskredet</span><?php endif; ?>
          <div class="occ-meta">
            <?= h(date_da($o['date'])) ?><?= $o['end'] !== $o['date'] ? ' – ' . h(date_da($o['end'], substr($o['end'], 0, 4) !== substr($o['date'], 0, 4))) : '' ?>
            · <?= h(Recurrence::describe($ev)) ?>
          </div>
          <?php if ($o['completed']): ?>
            <div class="occ-by">✓ <?= h(Events::completedText($o['completed'])) ?></div>
          <?php endif; ?>
          <?php if (trim((string)$ev['description']) !== ''): ?>
            <div class="occ-desc"><?= h(trim($ev['description'])) ?></div>
            <button type="button" class="occ-more" hidden>Vis mere</button>
          <?php endif; ?>
          <?php if ($o['note'] !== null): ?>
            <div class="occ-note"><?= h($o['note']) ?></div>
            <div class="occ-by"><?= h(Events::noteText($o['note_at'], $o['note_by'])) ?></div>
          <?php endif; ?>
          <?php if ($o['links'] || $o['files']): ?>
            <div class="occ-attach">
              <?php foreach ($o['links'] as $l): ?><a href="<?= h($l['url']) ?>" target="_blank" rel="noopener"<?= $l['by_name'] !== null ? ' title="Tilføjet af ' . h($l['by_name']) . '"' : '' ?>>🔗 <?= h($l['label'] ?: $l['url']) ?></a><?php endforeach; ?>
              <?php foreach ($o['files'] as $f): ?><a href="download.php?id=<?= (int)$f['id'] ?>&amp;occ=1" title="<?= h(Events::uploadedText($f)) ?>">📎 <?= h($f['original_name']) ?></a><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($ev['people']): ?>
            <div class="chips"><?php foreach ($ev['people'] as $p): ?><span class="chip"><?= h($p['name']) ?></span><?php endforeach; ?></div>
          <?php endif; ?>
          <?php foreach ($o['prereqs'] as $p): ?>
            <div class="prereq <?= $p['done'] ? 'ok' : 'wait' ?>">
              <?= $p['done'] ? '✓' : '⏳' ?> Afhænger af <?= event_link($p['event'], $year, $isAdmin) ?>
              <?= $p['date'] ? '(' . h(date_da($p['date'], true)) . ')' : '(ingen forekomst)' ?>
            </div>
          <?php endforeach; ?>
          <?php foreach ($o['dependents'] as $d): ?>
            <div class="prereq">
              → Forudsætning for <?= event_link($d['event'], $year, $isAdmin) ?>
              <?= $d['date'] ? '(' . h(date_da($d['date'], true)) . ')' : '(ingen forekomst)' ?>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
</div>

<script type="application/json" id="occ-data"><?= json_encode($details, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<dialog class="modal" id="occ-modal" aria-labelledby="occ-modal-title" data-can-edit="<?= $canEdit ? 1 : 0 ?>">
 <div class="modal-body">
  <div class="modal-head">
    <h2 id="occ-modal-title" data-f="title"></h2>
    <button type="button" class="modal-close" data-close aria-label="Luk">×</button>
  </div>
  <form method="post" action="action.php" class="check modal-check" data-f="check">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="toggle">
    <input type="hidden" name="id">
    <input type="hidden" name="date">
    <input type="hidden" name="back">
    <label><input type="checkbox" name="done" value="1" <?= $canEdit ? '' : 'disabled' ?>> Opfyldt</label>
    <span class="modal-status wait" data-f="status" hidden>⏳ Venter på forudsætning</span>
    <span class="modal-status overdue" data-f="overdue" hidden>Overskredet</span>
    <span class="occ-by" data-f="completed" hidden></span>
  </form>
  <dl class="modal-facts">
    <dt>Kategori</dt><dd><span class="modal-cat" data-f="category"></span></dd>
    <dt>Periode</dt><dd data-f="period"></dd>
    <dt>Gentagelse</dt><dd data-f="recurrence"></dd>
    <dt data-row="people">Personer</dt><dd data-row="people"><div class="chips" data-f="people"></div></dd>
    <dt data-row="prereqs">Afhænger af</dt><dd data-row="prereqs" data-f="prereqs"></dd>
    <dt data-row="dependents">Forudsætning for</dt><dd data-row="dependents" data-f="dependents"></dd>
  </dl>
  <div class="modal-desc" data-f="description"></div>
  <form method="post" action="action.php" enctype="multipart/form-data" class="modal-occ" data-f="note-form">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="note">
    <input type="hidden" name="id">
    <input type="hidden" name="date">
    <input type="hidden" name="back">
    <h3>Kun denne forekomst</h3>
    <label data-f="note-label">Note<textarea name="note" rows="3" maxlength="5000" <?= $canEdit ? '' : 'disabled' ?> placeholder="Fx &quot;Mødet holdes på Teams&quot; eller &quot;Afventer tal fra økonomi&quot;"></textarea></label>
    <div class="occ-by" data-f="note-by" hidden></div>
    <div class="field-label" data-f="links-label">Links</div>
    <ul class="plain" data-f="link-list"></ul>
    <div id="occ-links" <?= $canEdit ? '' : 'hidden' ?>>
      <div class="row link-row">
        <label class="grow">URL<input type="url" name="link_url[]" placeholder="https://"></label>
        <label class="grow">Tekst<input name="link_label[]"></label>
        <button type="button" class="btn small" data-remove-row>✕</button>
      </div>
    </div>
    <?php if ($canEdit): ?>
    <button type="button" class="btn small" data-add-link="occ-links">+ Tilføj link</button>
    <?php endif; ?>
    <div class="field-label modal-files-label" data-f="files-label">Filer</div>
    <ul class="plain files" data-f="files"></ul>
    <label <?= $canEdit ? '' : 'hidden' ?>>Upload filer <small>(max <?= (int)config('max_upload_mb') ?> MB pr. fil)</small><input type="file" name="files[]" multiple></label>
    <?php if ($canEdit): ?>
    <button class="btn primary" type="submit">Gem note</button>
    <?php endif; ?>
  </form>
  <div class="actions">
    <a class="btn primary" data-f="edit" href="#">Redigér begivenhed</a>
    <button type="button" class="btn" data-close>Luk</button>
  </div>
 </div>
</dialog>
<?php page_footer();
