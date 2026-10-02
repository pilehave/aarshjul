<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_post_csrf();
$back = (string)($_POST['back'] ?? 'index.php');
if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
    $back = 'index.php';
}

if (($_POST['do'] ?? '') === 'toggle') {
    $id = (int)$_POST['id'];
    $date = (string)$_POST['date'];
    $done = !empty($_POST['done']);
    $all = Events::all();
    if (!isset($all[$id]) || !in_array($date, Recurrence::occurrences($all[$id], $date, $date), true)) {
        http_response_code(400);
        exit('Ukendt forekomst.');
    }
    if ($done) {
        $waiting = array_filter(Events::prerequisites($all[$id], $date, $all, Events::completions()), fn($p) => !$p['done']);
        if ($waiting) {
            flash('"' . $all[$id]['title'] . '" venter på: ' . implode(', ', array_map(fn($p) => $p['event']['title'], $waiting)), 'error');
            redirect($back);
        }
    }
    Events::setCompleted($id, $date, $done);
}
redirect($back);
