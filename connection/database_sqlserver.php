<?php
// Shared SQL Server (Softland) connection; local overrides in database_sqlserver.local.php (excluded from Git).
function database_sqlserver(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = [
        'server' => getenv('SQLSRV_SERVER') ?: 'IT-SVALLE\MSSQLSERVER22',
        'dbname' => getenv('SQLSRV_DB') ?: 'SOFTLAND',
        'user' => getenv('SQLSRV_USER') ?: null, // null = Autenticación de Windows
        'password' => getenv('SQLSRV_PASSWORD') ?: null,
    ];
    $local = __DIR__ . '/database_sqlserver.local.php';
    if (is_file($local)) $config = array_replace($config, require $local);
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('Habilitá pdo_sqlsrv en php.ini y reiniciá Apache.');
    }

    $dsn = "sqlsrv:Server={$config['server']};Database={$config['dbname']};TrustServerCertificate=true;LoginTimeout=5";

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
?>