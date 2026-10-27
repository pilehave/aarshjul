<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

// Søgning i alle begivenheder uanset år (Enter eller "Søg i alle år" på forsiden). Se Events::search
$query = trim((string)($_GET['q'] ?? ''));
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$year = selected_year();
$searched = $query !== '' || $personId || $categoryId;
$today = date('Y-m-d');
$results = $searched ? Events::search(Events::all(), $query, $personId, $categoryId, $today) : [];
$people = Events::people();
$categories = Categories::all();
$isAdmin = Auth::can('admin');
$filters = ['q' => $query ?: null, 'person' => $personId, 'category' => $categoryId];

page_header($query !== '' ? "Søgning: $query" : 'Søgning');
?>
<p><a href="index.php?<?= h(http_build_query(array_filter(['year' => $year] + $filters))) ?>">‹ Tilbage til årshjulet <?= h(year_label($year)) ?></a></p>
<h1>Søg i begivenheder</h1>

<form method="get" class="filter search-page" role="search">
  <input type="hidden" name="year" value="<?= $year ?>">
  <label><span class="field-label">Søg</span>
    <input type="search" name="q" value="<?= h($query) ?>" placeholder="Titel, beskrivelse, kategori eller person" autofocus>
  </label>
  <label><span class="field-label">Person</span>
    <select name="person">
      <option value="">Alle</option>
      <?php foreach ($people as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $personId === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span class="field-label">Kategori</span>
    <select name="category">
      <option value="">Alle</option>
      <?php foreach ($categories as $id => $c): ?>
        <option value="<?= (int)$id ?>" <?= $categoryId === (int)$id ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn primary" type="submit">Søg</button>
</form>

<section class="list search-results">
  <?php if (!$searched): ?>
    <p class="muted">Skriv et ord fra en titel, en beskrivelse, en kategori eller et navn.</p>
  <?php elseif (!$results): ?>
    <p class="muted">Ingen begivenheder matcher søgningen.</p>
  <?php else: ?>
    <p class="muted"><?= count($results) ?> begivenhed<?= count($results) === 1 ? '' : 'er' ?></p>
  <?php endif; ?>
  <?php foreach ($results as ['event' => $ev, 'next' => $next, 'last' => $last]):
    $date = $next ?? $last;
    // Åbner forekomsten i det årshjul, den ligger i, med samme søgning (se #forekomst= i app.js)
    $wheelUrl = $date ? 'index.php?' . http_build_query(array_filter(['year' => wheel_year($date)] + $filters)) . '#forekomst=' . (int)$ev['id'] . '_' . $date : null;
  ?>
    <article class="occ" style="--c: <?= h($ev['color']) ?>">
      <div class="occ-body">
        <?php if ($wheelUrl): ?>
          <a class="occ-title" href="<?= h($wheelUrl) ?>"><?= h($ev['title']) ?></a>
        <?php else: ?>
          <span class="occ-title"><?= h($ev['title']) ?></span>
        <?php endif; ?>
        <span class="occ-cat"><?= h($ev['category_name']) ?></span>
        <div class="occ-meta">
          <?php if ($next && $next < $today): ?>I gang siden <?= h(date_da($next, true)) ?>
          <?php elseif ($next): ?>Næste gang <?= h(date_da($next, true)) ?>
          <?php elseif ($last): ?>Sidste gang <?= h(date_da($last, true)) ?>
          <?php else: ?>Ingen forekomster
          <?php endif; ?>
          · <?= h(Recurrence::describe($ev)) ?>
          <?php if ($isAdmin): ?> · <a href="event.php?id=<?= (int)$ev['id'] ?>&amp;year=<?= $year ?>">Redigér</a><?php endif; ?>
        </div>
        <?php if (trim((string)$ev['description']) !== ''): ?>
          <div class="occ-desc"><?= h(trim($ev['description'])) ?></div>
          <button type="button" class="occ-more" hidden>Vis mere</button>
        <?php endif; ?>
        <?php if ($ev['people']): ?>
          <div class="chips"><?php foreach ($ev['people'] as $p): ?><span class="chip"><?= h($p['name']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php page_footer();
