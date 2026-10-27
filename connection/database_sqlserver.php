<?php

function database_sqlserver(): PDO
{
    static $pdo;
    if (!$pdo) {
        $serverName = 'IT-SVALLE\MSSQLSERVER22';
        $database = 'SOFTLAND';

        $dsn = "sqlsrv:Server={$serverName};Database={$database};TrustServerCertificate=true;Encrypt=false";

        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
?>