<?php
// conexion_sqlserver.php

class ConexionSQLServer {
    // Usamos comillas simples o doble barra para evitar problemas con la barra invertida
    private $host = "DESKTOP-R7SPSOM\\MSSQLSERVER1"; 
    private $dbname = "SOFTLAND";
    
    // Cambia esto por un usuario de SQL Server (Autenticación SQL Server)
    private $usuario = "tu_usuario_sql"; 
    private $password = "tu_contraseña_sql";
    
    private $conn = null;

    public function obtenerConexion() {
        if ($this->conn === null) {
            try {
                // Se añade TrustServerCertificate=true por los requisitos de cifrado de SQL Server
                $dsn = "sqlsrv:Server={$this->host};Database={$this->dbname};TrustServerCertificate=true";

                $this->conn = new PDO(
                    $dsn,
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