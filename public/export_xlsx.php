<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Xlsx.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;

$rows = [['Startdato', 'Slutdato', 'Titel', 'Beskrivelse', 'Gentagelse', 'Personer', 'Afhænger af', 'Klar (forudsætninger opfyldt)', 'Opfyldt', 'Links', 'Filer']];
foreach (Events::occurrencesForYear($year, $personId) as $o) {
    $e = $o['event'];
    $rows[] = [
        new DateTimeImmutable($o['date']),
        new DateTimeImmutable($o['end']),
        $e['title'],
        (string)$e['description'],
        Recurrence::describe($e),
        implode(', ', array_column($e['people'], 'name')),
        implode(', ', array_map(fn($p) => $p['event']['title'] . ($p['done'] ? ' ✓' : ''), $o['prereqs'])),
        $o['blocked'] ? 'Nej' : 'Ja',
        $o['done'] ? 'Ja' : 'Nej',
        implode("\n", array_map(fn($l) => ($l['label'] ? $l['label'] . ': ' : '') . $l['url'], $e['links'])),
        implode("\n", array_column($e['files'], 'original_name')),
    ];
}

$data = Xlsx::build("Årshjul $year", $rows, [12, 12, 30, 45, 30, 28, 28, 14, 10, 45, 30]);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"aarshjul-$year.xlsx\"");
header('Content-Length: ' . strlen($data));
echo $data;
