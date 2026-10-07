<?php
declare(strict_types=1);

/**
 * Sender en testmail med den aktuelle SMTP-opsætning.
 * Kør:  docker compose exec web php bin/send-test-mail.php modtager@example.com
 */

if (PHP_SAPI !== 'cli') {
    exit("Kun fra kommandolinjen.\n");
}
$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Brug: php bin/send-test-mail.php modtager@example.com\n");
    exit(2);
}

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../src/bootstrap.php';

printf("Sender via %s:%s (%s) fra %s ...\n", config('smtp_host'), config('smtp_port'), config('smtp_secure'), config('mail_from'));
$errors = Mailer::send($to, 'Testmail fra Årshjul', "Hej!\n\nDette er en testmail fra Årshjul (" . config('app_url') . ").\nSendt " . date('j/n Y H:i') . ".\n");
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Sendt.\n";
