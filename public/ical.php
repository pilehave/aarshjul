<?php
declare(strict_types=1);
// Kalenderabonnement (iCal). Kalenderprogrammer kan ikke logge ind, så adgangen styres af brugerens hemmelige
// nøgle i adressen (?token=, se Ical). Uden en gyldig nøgle fra en aktiv bruger vises intet.
const PUBLIC_PAGE = true;
const NO_SESSION = true;
require __DIR__ . '/../src/bootstrap.php';

header('X-Robots-Tag: noindex');
$user = Ical::userByToken((string)($_GET['token'] ?? ''));
if (!$user) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kalenderen findes ikke. Adressen kan være forkert eller udskiftet, eller brugeren er deaktiveret.');
}

$personId = (int)($_GET['person'] ?? 0) ?: null;
$categoryId = (int)($_GET['category'] ?? 0) ?: null;
[$from, $to] = Ical::window(date('Y-m-d'));
$occ = Events::occurrencesBetween($from, $to, $personId, $categoryId);

// Kalenderens navn, fx "Årshjul – Anne Holm". Filnavnet efter ical.php/ i adressen (se Ical::url) bruges ikke her
$name = Ical::name($personId, $categoryId);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . Ical::filename($name) . '"');
header('Cache-Control: no-cache');
echo Ical::calendar($occ, $name, time(), config('app_url'));
