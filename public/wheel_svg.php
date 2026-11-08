<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$period = Period::fromRequest(selected_year(), $_GET['zoom'] ?? null);
$year = $period['year'];
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$onlyMissing = !empty($_GET['missing']);
header('Content-Type: image/svg+xml; charset=utf-8');
if (!empty($_GET['download'])) {
    header("Content-Disposition: attachment; filename=\"aarshjul-" . str_replace(['/', ' '], '-', Period::label($period)) . ".svg\"");
}
$svg = Wheel::svg($year, Events::occurrencesBetween($period['from'], $period['to'], $personId, $categoryId, null, $onlyMissing), false, $period);
// Fonten lægges ind i filen, så hjulet ser ens ud i programmer, der ikke har Geist installeret
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", preg_replace('/<defs>/', Wheel::fontStyle() . '<defs>', $svg, 1);
