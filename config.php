<?php
// Konfiguration. Kan overstyres med miljøvariabler (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
// som Docker-miljøet sætter, eller de ældre AARSHJUL_DB_HOST osv.)
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
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}
unset($env);
return $config;
