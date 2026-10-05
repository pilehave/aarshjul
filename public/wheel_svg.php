<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$onlyMissing = !empty($_GET['missing']);
header('Content-Type: image/svg+xml; charset=utf-8');
if (!empty($_GET['download'])) {
    header("Content-Disposition: attachment; filename=\"aarshjul-" . str_replace('/', '-', year_label($year)) . ".svg\"");
}
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", Wheel::svg($year, Events::occurrencesForYear($year, $personId, $categoryId, null, $onlyMissing), false);
