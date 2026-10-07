<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_role('contributor');

require_post_csrf();
$back = local_path((string)($_POST['back'] ?? ''));

$do = (string)($_POST['do'] ?? '');
if ($do === 'toggle' || $do === 'note') {
    $id = (int)$_POST['id'];
    $date = (string)$_POST['date'];
    $all = Events::all();
    if (!isset($all[$id]) || !in_array($date, Recurrence::occurrences($all[$id], $date, $date), true)) {
        http_response_code(400);
        exit('Ukendt forekomst.');
    }
    if ($do === 'toggle') {
        $done = !empty($_POST['done']);
        if ($done) {
            $waiting = array_filter(Events::prerequisites($all[$id], $date, $all, Events::completions()), fn($p) => !$p['done']);
            if ($waiting) {
                flash('"' . $all[$id]['title'] . '" venter på: ' . implode(', ', array_map(fn($p) => $p['event']['title'], $waiting)), 'error');
                redirect($back);
            }
        }
        Events::setCompleted($id, $date, $done);
    } else {
        // Note, links og filer, der kun gælder denne forekomst
        $errors = Events::saveOccurrence($id, $date, $_POST, $_FILES['files'] ?? []);
        flash($errors ? implode(' ', $errors) : 'Noten er gemt.', $errors ? 'error' : 'ok');
    }
}
redirect($back);
