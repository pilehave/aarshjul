<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$st = db()->prepare('SELECT * FROM event_files WHERE id = ?');
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
