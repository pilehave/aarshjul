<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Copenhagen');
session_start();

$GLOBALS['config'] = require __DIR__ . '/../config.php';

require __DIR__ . '/Recurrence.php';
require __DIR__ . '/Categories.php';
require __DIR__ . '/People.php';
require __DIR__ . '/Events.php';
require __DIR__ . '/Wheel.php';

function config(string $key)
{
    return $GLOBALS['config'][$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('db_host'), config('db_port'), config('db_name'));
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ugyldig forespørgsel (CSRF). Genindlæs siden og prøv igen.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

const MONTHS_DA = [1 => 'januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli',
    'august', 'september', 'oktober', 'november', 'december'];
const WEEKDAYS_DA = [1 => 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];

function date_da(string $ymd, bool $withYear = false): string
{
    $d = new DateTimeImmutable($ymd);
    return $d->format('j') . '. ' . MONTHS_DA[(int)$d->format('n')] . ($withYear ? ' ' . $d->format('Y') : '');
}

function selected_year(): int
{
    $y = (int)($_GET['year'] ?? date('Y'));
    return ($y >= 1970 && $y <= 2200) ? $y : (int)date('Y');
}
