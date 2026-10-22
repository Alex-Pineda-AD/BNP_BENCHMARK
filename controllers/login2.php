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
        // Permite buscar tanto por nombre de usuario
        $stmt = $pdo->prepare("SELECT ID, USUARIO, PASSWORD, ID_ROL FROM usuarios WHERE USUARIO = :user AND ESTADO = 1 LIMIT 1");
        $stmt->execute([':user' => $usuario]);
        $usuario1 = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar la contraseña cifrada
        if ($usuario1 && password_verify($password, $usuario1['PASSWORD'])) {
            
            // Iniciar sesión exitosa
            session_regenerate_id(true);
            $_SESSION['user_id'] = $usuario1['ID'];
            $_SESSION['user_nombre'] = $usuario1['USUARIO'];
            $_SESSION['id_rol'] = $usuario1['ID_ROL'];

            // Redireccionar al dashboard o página principal
            if ($_SESSION['id_rol']==3) {
                 header("Location: ../modules/conciliacion/depositos.php");
            }elseif ($_SESSION['id_rol']==2) {
                 header("Location: ../modules/conciliacion/depositos_revision.php");
            }elseif ($_SESSION['id_rol']==4 ) {
                 header("Location: ../modules/conciliacion/depositos_revision.php");
            }elseif ($_SESSION['id_rol']==5) {
                 header("Location: ../modules/conciliacion/depositos_revision.php");
            } elseif ($_SESSION['id_rol'] == 6) { 
            // Apuntando correctamente a tu carpeta benchmark y archivo
            header("Location: ../modules/benchmark/benchmark_competidores.php");
}
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