<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();

    // Gmail SMTP
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'banasuprob@gmail.com';

    // CONTRASEÑA DE APLICACIÓN DE GOOGLE
    $mail->Password   = 'zmau cotp dgip rvny';

    // TLS
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Remitente
    $mail->setFrom(
        'banasuprob@gmail.com',
        'Conciliacion'
    );

    // Destinatarios
    $mail->addAddress('edgar.melara@banasupro.hn');
    $mail->addAddress('eduardo.ramirez@banasupro.hn');
    $mail->addAddress('fernandoizaguirrehn7@gmail.com');

    // Mensaje
    $mail->isHTML(true);
    $mail->Subject = 'Correo de prueba';

    $mail->Body = '
        <h2>Hola</h2>
        <p>Correo enviado mediante Gmail y PHPMailer.</p>
    ';

    $mail->AltBody = 'Correo enviado mediante Gmail y PHPMailer.';

    $mail->send();

    echo 'Correo enviado correctamente';

} catch (Exception $e) {

    echo 'Error: ' . $mail->ErrorInfo;
}
