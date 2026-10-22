<?php
session_start();
require_once 'conexion.php';

// Verificar que la solicitud sea POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Sanitizar y obtener los datos enviados desde el formulario
    $USUARIO = trim($_POST['USUARIO'] ?? '');
    $PASSWORD = trim($_POST['PASSWORD'] ?? '');

    // Validar que los campos no estén vacíos
    if (empty($USUARIO) || empty($PASSWORD)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Por favor llena todos los campos."]);
        exit;
    }

    try {
        // Consultar el usuario en la base de datos
        $stmt = $pdo->prepare("SELECT ID, NOMBRE_COMPLETO, CORREO, USUARIO, PASSWORD FROM usuarios WHERE USUARIO = :USUARIO LIMIT 1");
        $stmt->execute([':USUARIO' => $USUARIO]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar si el usuario existe y comprobar la PASSWORD
        if ($usuario && password_verify($PASSWORD, $usuario['PASSWORD'])) {
            
            // Prevenir ataques de fijación de sesión
            session_regenerate_id(true);

            // Guardar datos en la sesión
            $_SESSION['user_id'] = $usuario['ID'];
            $_SESSION['user_nombre'] = $usuario['NOMBRE_COMPLETO'];
            $_SESSION['user_correo'] = $usuario['CORREO'];

            // Si es una petición API/Fetch (React o JS), retornar JSON
            echo json_encode([
                "status" => "success",
                "message" => "Inicio de sesión exitoso.",
                "usuario" => [
                    "id" => $usuario['ID'],
                    "nombre" => $usuario['NOMBRE_COMPLETO'],
                    "email" => $usuario['CORREO']
                ]
            ]);
            exit;

        } else {
            // Mensaje genérico para no dar pistas a atacantes
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Credenciales incorrectas."]);
            exit;
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error en el servidor: " . $e->getMessage()]);
        exit;
    }

} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido."]);
    exit;
}
?>