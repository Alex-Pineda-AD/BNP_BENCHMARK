<?php
require_once __DIR__ . '/../connection/database.php';

try {
    $pdo = database();
    
    // Define la contraseña en texto plano que quieres usar para entrar
    $nueva_clave_plana = 'admin';
    
    // Genera el hash de forma limpia
    $hash = password_hash($nueva_clave_plana, PASSWORD_DEFAULT);
    
    // Actualiza directamente en la base de datos para el usuario con ID = 1
    $stmt = $pdo->prepare("UPDATE usuarios SET PASSWORD = :pass, ESTADO = 1 WHERE ID = 1");
    $stmt->execute([':pass' => $hash]);
    
    echo "Contraseña actualizada con éxito.<br>";
    echo "Usuario: admin<br>";
    echo "Nueva Clave: " . $nueva_clave_plana;
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>