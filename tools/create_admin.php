<?php
// CLI only. Never replaces an existing account or stores a plaintext password.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/database.php';
$pdo = database();
$query = $pdo->prepare('SELECT ID FROM usuarios WHERE USUARIO = ?');
$query->execute(['admin']);
if ($query->fetch()) { echo "El usuario admin ya existe; no se modificó.\n"; exit; }
$password = bin2hex(random_bytes(10));
$pdo->prepare('INSERT INTO usuarios (USUARIO, PASSWORD, NOMBRE_COMPLETO, DNI, FECHA_CREO, USUARIO_CREO, ESTADO, USUARIO_HASH, ID_ROL, NUM_TELEFONO) VALUES (?, ?, ?, ?, NOW(), 0, 1, ?, 5, ?)')
    ->execute(['admin', password_hash($password, PASSWORD_DEFAULT), 'Administrador', '', bin2hex(random_bytes(24)), '']);
echo "Usuario: admin\nContraseña inicial: $password\n";
