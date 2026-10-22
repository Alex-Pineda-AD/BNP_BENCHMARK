<?php
// Shared PostgreSQL connection; local credentials are excluded from Git.
function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '5432',
        'dbname' => getenv('DB_NAME') ?: 'banasupro2',
        'user' => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: 'hola1010',
        'schema' => getenv('DB_SCHEMA') ?: 'public',
    ];
    $local = __DIR__ . '/database.local.php';
    if (is_file($local)) $config = array_replace($config, require $local);
    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Habilitá pdo_pgsql en php.ini y reiniciá Apache.');
    }
    foreach (['host', 'port', 'dbname'] as $key) {
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', (string) $config[$key])) {
            throw new RuntimeException('Configuración PostgreSQL inválida: ' . $key);
        }
    }
    $connection = new PDO('pgsql:host=' . $config['host'] . ';port=' . $config['port']
        . ';dbname=' . $config['dbname'] . ';connect_timeout=5', $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_CASE => PDO::CASE_UPPER,
    ]);
    $connection->exec("SET client_encoding TO 'UTF8'");
    $connection->exec("SET TIME ZONE 'America/Tegucigalpa'");
    $connection->exec('SET search_path TO "' . str_replace('"', '""', $config['schema']) . '"');
    $pdo = $connection;
    return $pdo;
}
