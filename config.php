<?php
// Konfiguration. Kan overstyres med miljøvariabler (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
// APP_URL, SMTP_*, MAIL_FROM*, som Docker-miljøet sætter, eller med præfikset AARSHJUL_)
// eller ved at oprette config.local.php, der returnerer et array med de samme nøgler.
$env = static function (string $name, string $default): string {
    foreach (["AARSHJUL_$name", $name] as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return $default;
};
$config = [
    'db_host'       => $env('DB_HOST', '127.0.0.1'),
    'db_port'       => $env('DB_PORT', '3306'),
    'db_name'       => $env('DB_NAME', 'aarshjul'),
    'db_user'       => $env('DB_USER', 'aarshjul'),
    'db_pass'       => $env('DB_PASS', 'aarshjul'),
    'upload_dir'    => __DIR__ . '/storage/uploads',
    'max_upload_mb' => 20,
    // Adressen, årshjulet ligger på. Bruges til links i mails (aldrig Host-headeren).
    'app_url'        => rtrim($env('APP_URL', 'http://localhost:8080'), '/'),
    // Udgående mail (se src/Mailer.php). smtp_secure: tls (STARTTLS), ssl eller none (kun Mailpit).
    'smtp_host'      => $env('SMTP_HOST', 'localhost'),
    'smtp_port'      => $env('SMTP_PORT', '587'),
    'smtp_secure'    => $env('SMTP_SECURE', 'tls'),
    'smtp_user'      => $env('SMTP_USER', ''),
    'smtp_pass'      => $env('SMTP_PASS', ''),
    'mail_from'      => $env('MAIL_FROM', ''),
    'mail_from_name' => $env('MAIL_FROM_NAME', 'Årshjul'),
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}
unset($env);
return $config;
