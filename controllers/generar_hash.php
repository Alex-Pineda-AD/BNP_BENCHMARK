<?php
// Cambia 'admin' por la contraseña que quieras usar
$password_plana = 'admin';
echo "Copia este hash exacto:<br><br>";
echo password_hash($password_plana, PASSWORD_DEFAULT);
?>