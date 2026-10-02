<?php
// Konfiguration. Kan overstyres med miljøvariabler (AARSHJUL_DB_HOST osv.)
// eller ved at oprette config.local.php, der returnerer et array med de samme nøgler.
$config = [
    'db_host'       => getenv('AARSHJUL_DB_HOST') ?: '127.0.0.1',
    'db_port'       => getenv('AARSHJUL_DB_PORT') ?: '3306',
    'db_name'       => getenv('AARSHJUL_DB_NAME') ?: 'aarshjul',
    'db_user'       => getenv('AARSHJUL_DB_USER') ?: 'aarshjul',
    'db_pass'       => getenv('AARSHJUL_DB_PASS') ?: 'aarshjul',
    'upload_dir'    => __DIR__ . '/storage/uploads',
    'max_upload_mb' => 20,
];
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}
return $config;
