<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_role('admin');

$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;

$events = array_filter(Events::all(), fn($e) => (!$personId || in_array($personId, array_column($e['people'], 'id'), true))
    && (!$categoryId || (int)$e['category_id'] === $categoryId));
// Uden filter kommer alle kategorier og personer med, også ubrugte, så filen kan bruges som backup
$tpl = Template::build($events, Categories::all(), People::all(), $personId !== null || $categoryId !== null);

$data = json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="aarshjul-skabelon-' . date('Y-m-d') . '.json"');
header('Content-Length: ' . strlen($data));
echo $data;
