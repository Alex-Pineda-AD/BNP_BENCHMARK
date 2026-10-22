<?php
session_start();
/* require_once '../connection/connection.php'; */
require_once __DIR__ . '/../connection/database.php';

try {
    $pdo = database();
} catch (PDOException $e) {
    // Esto te mostrará la causa exacta del fallo de conexión
    die("Error detallado: " . $e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuario = trim($_POST['USUARIO'] ?? '');
    $password = trim($_POST['PASSWORD'] ?? '');

    // Validar que los campos no estén vacíos
    if (empty($usuario) || empty($password)) {
        header("Location: ../index.html?error=" . urlencode("Por favor llena todos los campos."));
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT ID, USUARIO, PASSWORD, ESTADO FROM usuarios WHERE USUARIO = :user LIMIT 1");
        $stmt->execute([':user' => $usuario]);
        $usuario1 = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario1) {
            die("Error: No se encontró ningún usuario con el nombre '$usuario'.");
        }

        if ($usuario1['ESTADO'] != 1) {
            die("Error: El usuario existe pero su ESTADO es " . var_export($usuario1['ESTADO'], true) . " (se requiere ESTADO = 1).");
        }

        if (password_verify($password, $usuario1['PASSWORD'])) {
            die("¡ÉXITO! La contraseña es correcta.");
        } else {
            die("Error: El usuario existe y está activo, pero la contraseña no coincide con el hash guardado.");
        }

    } catch (PDOException $e) {
        die("Error SQL: " . $e->getMessage());
    }

    try {
        // Permite buscar tanto por nombre de usuario
        $stmt = $pdo->prepare("SELECT ID, USUARIO, PASSWORD FROM usuarios WHERE USUARIO = :user AND ESTADO = 1 LIMIT 1");
        $stmt->execute([':user' => $usuario]);
        $usuario1 = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar la contraseña cifrada
        if ($usuario1 && password_verify($password, $usuario1['PASSWORD'])) {
            
            // Iniciar sesión exitosa
            session_regenerate_id(true);
            $_SESSION['user_id'] = $usuario1['ID'];
            $_SESSION['user_nombre'] = $usuario1['USUARIO'];

            // Redireccionar al dashboard o página principal
            header("Location: ../modules/conciliacion/index.php");
            exit;

        } else {
            // Credenciales inválidas
            header("Location: ../index.html?error=" . urlencode("Usuario o contraseña incorrectos."));
            exit;
        }

    } catch (PDOException $e) {
        header("Location: ../index.html?error=" . urlencode("Error en el servidor al intentar iniciar sesión."));
        exit;
    }

} else {
    // Si intentan acceder directamente por GET
    header("Location: ../index.html");
    exit;
}
?>