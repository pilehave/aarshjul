<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// ?id=N er en fil på en begivenhed, ?id=N&occ=1 en fil på en enkelt forekomst
$table = empty($_GET['occ']) ? 'event_files' : 'occurrence_files';
$st = db()->prepare("SELECT * FROM $table WHERE id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$path = $f ? config('upload_dir') . '/' . $f['stored_name'] : null;
if (!$f || !is_file($path)) {
    http_response_code(404);
    exit('Filen findes ikke.');
}
$inline = preg_match('~^(image/(png|jpeg|gif|webp)|application/pdf|text/plain)$~', $f['mime_type']);
header('Content-Type: ' . $f['mime_type']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header(sprintf("Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
    $inline ? 'inline' : 'attachment', preg_replace('/[^\x20-\x7e]|"/', '_', $f['original_name']), rawurlencode($f['original_name'])));
readfile($path);
