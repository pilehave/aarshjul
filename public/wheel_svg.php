<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
header('Content-Type: image/svg+xml; charset=utf-8');
if (!empty($_GET['download'])) {
    header("Content-Disposition: attachment; filename=\"aarshjul-$year.svg\"");
}
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", Wheel::svg($year, Events::occurrencesForYear($year, $personId), false);
