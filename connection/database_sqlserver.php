<?php

function database_sqlserver(): PDO
{
    static $pdo;
    if (!$pdo) {
        $serverName = 'IT-ERAMIREZ\MSSQLSERVER1'; // O "localhost\SQLEXPRESS" / IP
        $database = 'SOFTLAND';
        $username = 'IT-ERAMIREZ\MSSQLSERVER1';
        $password = '';

        $dsn = "sqlsrv:Server={$serverName};Database={$database}";

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
?>