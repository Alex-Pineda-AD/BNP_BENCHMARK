<?php
// conexion_sqlserver.php

class ConexionSQLServer {
    private $host = "IT-ERAMIREZ\MSSQLSERVER1"; // ej: localhost o SERVER\SQLEXPRESS
    private $dbname = "SOFTLAND";
    private $usuario = "IT-ERAMIREZ\MSSQLSERVER1";
    private $password = "";
    private $conn = null;

    public function obtenerConexion() {
        if ($this->conn === null) {
            try {
                // Driver ODBC o SQLSRV para PDO
                $this->conn = new PDO(
                    "sqlsrv:Server={$this->host};Database={$this->dbname}",
                    $this->usuario,
                    $this->password
                );
                // Configurar errores en modo excepción
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                die("Error de conexión a SQL Server: " . $e->getMessage());
            }
        }
        return $this->conn;
    }
}
?>