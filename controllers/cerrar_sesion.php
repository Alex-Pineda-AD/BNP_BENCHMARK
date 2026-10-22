<?php
session_start();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.html');
    exit;
}
if (empty($_SESSION['cerrar_sesion_token']) || !is_string($_POST['token'] ?? null)
    || !hash_equals($_SESSION['cerrar_sesion_token'], $_POST['token'])) {
    http_response_code(403);
    exit('Recargá la pantalla para cerrar sesión.');
}

// Vaciar los datos, eliminar la cookie y destruir la sesión del servidor.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
}
session_destroy();
header('Location: ../index.html');
exit;
