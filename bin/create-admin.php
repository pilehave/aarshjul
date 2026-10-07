<?php
declare(strict_types=1);

/**
 * Opretter en administrator, eller gør en eksisterende bruger til administrator med ny adgangskode
 * (fx hvis man har låst sig selv ude). Adgangskoden spørges der om to gange.
 *
 * Kør:  docker compose exec web php bin/create-admin.php [mail] [navn]
 */

if (PHP_SAPI !== 'cli') {
    exit("Kun fra kommandolinjen.\n");
}
ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../src/bootstrap.php';

// Tjekkes før første læsning (stream_isatty() på en delvist læst STDIN giver advarsler).
// Skjult indtastning kræver stty, som ikke findes på Windows.
define('STDIN_IS_TTY', PHP_OS_FAMILY !== 'Windows' && stream_isatty(STDIN));

function ask(string $prompt, bool $hidden = false): string
{
    echo $prompt;
    $tty = $hidden && STDIN_IS_TTY;
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    if ($line === false) {
        fwrite(STDERR, "\nAfbrudt.\n");
        exit(1);
    }
    return rtrim($line, "\r\n");
}

$email = mb_strtolower(trim($argv[1] ?? ask('Mail: ')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Ugyldig mailadresse.\n");
    exit(1);
}

try {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $existing = $st->fetch() ?: null;
} catch (PDOException $e) {
    fwrite(STDERR, "Kunne ikke læse brugertabellen. Er migrationen sql/migrations/2026-10-07-users.sql kørt?\n" . $e->getMessage() . "\n");
    exit(1);
}

$name = $existing ? $existing['name'] : trim($argv[2] ?? ask('Navn: '));
if ($name === '' || mb_strlen($name) > 150) {
    fwrite(STDERR, "Navnet skal være 1-150 tegn.\n");
    exit(1);
}
if ($existing) {
    echo "$email findes allerede ($name). Brugeren gøres til aktiv administrator med ny adgangskode.\n";
}

echo "Adgangskoden skal være 12-256 tegn med mindst ét stort bogstav, ét lille bogstav og ét tal.\n";
while (true) {
    $errors = Auth::validatePassword(ask('Adgangskode: ', true), $repeat = ask('Gentag adgangskode: ', true));
    if (!$errors) {
        break;
    }
    echo implode("\n", $errors) . "\n";
}

$hash = Auth::hash($repeat);
if ($existing) {
    db()->prepare("UPDATE users SET password_hash = ?, role = 'admin', active = 1 WHERE id = ?")->execute([$hash, $existing['id']]);
    echo "Opdateret.\n";
} else {
    db()->prepare("INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, 'admin')")->execute([$email, $name, $hash]);
    echo "Administratoren $name ($email) er oprettet.\n";
}
