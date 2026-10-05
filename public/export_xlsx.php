<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Xlsx.php';

$year = selected_year();
$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
$onlyMissing = !empty($_GET['missing']);

$rows = [['Startdato', 'Slutdato', 'Titel', 'Beskrivelse', 'Note', 'Gentagelse', 'Personer', 'Afhænger af', 'Klar (forudsætninger opfyldt)', 'Opfyldt', 'Overskredet', 'Links', 'Filer']];
foreach (Events::occurrencesForYear($year, $personId, $categoryId, null, $onlyMissing) as $o) {
    $e = $o['event'];
    $rows[] = [
        new DateTimeImmutable($o['date']),
        new DateTimeImmutable($o['end']),
        $e['title'],
        (string)$e['description'],
        (string)$o['note'],
        Recurrence::describe($e),
        implode(', ', array_column($e['people'], 'name')),
        implode(', ', array_map(fn($p) => $p['event']['title'] . ($p['done'] ? ' ✓' : ''), $o['prereqs'])),
        $o['blocked'] ? 'Nej' : 'Ja',
        $o['done'] ? 'Ja' : 'Nej',
        $o['overdue'] ? 'Ja' : 'Nej',
        // Links og filer på begivenheden efterfulgt af dem, der kun gælder denne forekomst
        implode("\n", array_map(fn($l) => ($l['label'] ? $l['label'] . ': ' : '') . $l['url'], [...$e['links'], ...$o['links']])),
        implode("\n", array_column([...$e['files'], ...$o['files']], 'original_name')),
    ];
}

$label = str_replace('/', '-', year_label($year)); // "/" må ikke indgå i et arknavn
$data = Xlsx::build("Årshjul $label", $rows, [12, 12, 30, 45, 40, 30, 28, 28, 14, 10, 12, 45, 30]);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"aarshjul-$label.xlsx\"");
header('Content-Length: ' . strlen($data));
echo $data;
