<?php
declare(strict_types=1);
// Logoet i topbaren vises også på login-siden, så det kræver ikke login. Det viser kun det logo,
// en administrator har valgt under Indstillinger, og ingen andre data.
const PUBLIC_PAGE = true;
require __DIR__ . '/../src/bootstrap.php';

$logo = Settings::topbarLogo();
if (!$logo || !is_file($logo['file'])) {
    http_response_code(404);
    exit('Der er intet logo.');
}
header('Content-Type: ' . $logo['type']);
header('Content-Length: ' . filesize($logo['file']));
header('X-Content-Type-Options: nosniff');
// En SVG kan indeholde scripts. Som <img> køres de aldrig, og åbnes filen direkte, blokerer sandbox dem
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
// Adressen indeholder logoets gemte navn (?v=), som skifter ved et nyt logo. session_start() har sat no-cache-headere
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: ' . (($_GET['v'] ?? '') === $logo['v'] ? 'public, max-age=31536000, immutable' : 'no-cache'));
readfile($logo['file']);
