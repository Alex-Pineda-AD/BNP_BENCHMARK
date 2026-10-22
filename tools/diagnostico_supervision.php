<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$pdo=database();
$q=$pdo->prepare('SELECT u.id,u.usuario,u.id_rol,u.estado FROM usuarios u WHERE LOWER(u.usuario)=LOWER(?)'); $q->execute(['fizaguirre']);
echo json_encode(['usuario'=>$q->fetch(),'roles'=>$pdo->query('SELECT * FROM roles')->fetchAll(),'vinculos'=>$pdo->query('SELECT v.id,v.deposito_id,v.estado,v.creado_por,u.usuario creador,v.revisado_por FROM conciliacion_vinculos v JOIN usuarios u ON u.id=v.creado_por WHERE v.deposito_id=10')->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
