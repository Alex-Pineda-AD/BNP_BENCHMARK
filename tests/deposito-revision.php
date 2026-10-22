<?php
// Reutiliza las tablas temporales de la prueba de edicion; no altera datos reales.
require __DIR__ . '/deposito-editar.php';
require __DIR__ . '/../includes/deposito_revision.php';
$p->exec('ALTER TABLE usuarios ADD ID_ROL INT DEFAULT 3');
$p->exec('UPDATE usuarios SET ID_ROL=2 WHERE ID=1');
$p->exec('DELETE FROM depositos_bitacora');
$p->exec('UPDATE depositos SET ESTADO=2 WHERE ID=1');
$fila=$p->query('SELECT * FROM depositos WHERE ID=1')->fetch();
$fila['ID_DEPOSITO_DETALLE']=$p->query('SELECT MAX(ID_DEPOSITO_DETALLE) FROM depositos_detalle WHERE ID_DEPOSITO=1')->fetchColumn();
$version=versionDepositoRevision($fila);
foreach ([[0,'',1,$version], [7,'',1,$version], [5,'',2,$version], [5,'',1,'desactualizado']] as [$estado,$motivo,$usuario,$huella]) {
    $p->beginTransaction();
    try {
        revisarDeposito($p,1,$estado,$motivo,$usuario,$huella);
        throw new RuntimeException('Se acepto una revision invalida');
    } catch (InvalidArgumentException $e) { $p->rollBack(); }
}
foreach ([7,5] as $estado) {
    $p->beginTransaction();
    revisarDeposito($p,1,$estado,'Foto comparada: verificar monto.',1,$version);
    comprobarEdicion($p->query('SELECT ESTADO FROM depositos WHERE ID=1')->fetchColumn()==$estado,'Estado incorrecto');
    $evento=$p->query('SELECT * FROM depositos_bitacora')->fetch();
    comprobarEdicion($evento['ESTADO_ANTERIOR']==2 && $evento['ESTADO_NUEVO']==$estado && $evento['ID_DEPOSITO_DETALLE']==$fila['ID_DEPOSITO_DETALLE'],'Bitacora incorrecta');
    comprobarEdicion($p->query('SELECT MONTO FROM depositos WHERE ID=1')->fetchColumn()==$fila['MONTO'],'Revision modifico los datos');
    if ($estado===5) {
        try { revisarDeposito($p,1,7,'Otra correccion',1,$version); throw new RuntimeException('Reabrio aprobado'); }
        catch (InvalidArgumentException $e) {}
    }
    $p->rollBack();
}
echo "OK: estados permitidos, motivo obligatorio, permisos, version, aprobacion/correccion y bitacora atomica.\n";

// Edicion directa y solicitud al usuario en la misma transaccion.
foreach ([7,5,8] as $estado) {
    $p->beginTransaction();
    revisarDeposito($p,1,$estado,'Corregir el comprobante.',1,$version,array_replace($datos,['CONFIRMADO'=>'1','MONTO'=>'25.75']));
    $guardado=$p->query('SELECT * FROM depositos WHERE ID=1')->fetch();
    comprobarEdicion($guardado['MONTO']==='25.75' && $guardado['CODIGO']===$fila['CODIGO'] && $guardado['USUARIO_CREO']===$fila['USUARIO_CREO'],'No guardo la edicion correctamente');
    $eventos=$p->query('SELECT * FROM depositos_bitacora ORDER BY ID')->fetchAll();
    comprobarEdicion(count($eventos)===2 && $eventos[0]['ACCION']==='EDICION' && $eventos[1]['OBSERVACION']==='Corregir el comprobante.','Falta edicion o motivo en bitacora');
    comprobarEdicion($eventos[1]['ACCION']===($estado===7?'SOLICITUD_CORRECCION':'APROBACION'),'Accion de revision incorrecta');
    $p->rollBack();
}
$p->beginTransaction();
$p->exec("INSERT INTO depositos (ID,ID_BANCO,ID_CENTRO_VENTA,NUMERO_CUENTA,NUMERO_REFERENCIA,MONTO,MONEDA,REMITENTE,BENEFICIARIO,COMENTARIO,ESTADO,USUARIO_CREO) VALUES (2,1,1,'123','ABC',5,'HNL','R','B','Duplicado',2,1)");
try {
    revisarDeposito($p,1,7,'Corregir foto',1,$version,array_replace($datos,['CONFIRMADO'=>'1']));
    throw new RuntimeException('Revision permitio referencia duplicada');
} catch (InvalidArgumentException $e) {
    comprobarEdicion(strpos($e->getMessage(),'ya está registrado')!==false,'Error inesperado al validar duplicado');
}
comprobarEdicion($p->query('SELECT COUNT(*) FROM depositos_bitacora')->fetchColumn()==0,'Guardo bitacora de revision rechazada');
$p->rollBack();
echo "OK: edicion del revisor, devolucion, aprobacion y referencias duplicadas.\n";
$p->beginTransaction();
$p->exec("INSERT INTO depositos_bitacora(ID_DEPOSITO,ACCION,ESTADO_ANTERIOR,ESTADO_NUEVO) VALUES (1,'APROBACION',2,5),(1,'SOLICITUD_CORRECCION',5,7); UPDATE depositos SET ESTADO=7 WHERE ID=1");
$devuelto=$p->query('SELECT * FROM depositos WHERE ID=1')->fetch(); $devuelto['ID_DEPOSITO_DETALLE']=$fila['ID_DEPOSITO_DETALLE'];
revisarDeposito($p,1,5,'Monto corregido luego de devolución supervisada.',1,versionDepositoRevision($devuelto),array_replace($datos,['CONFIRMADO'=>'1','MONTO'=>'25.75']));
comprobarEdicion($p->query('SELECT MONTO FROM depositos WHERE ID=1')->fetchColumn()==='25.75','No permitió corregir y aprobar el depósito devuelto');
$p->rollBack();
echo "OK: nueva revisión y aprobación luego de devolución supervisada.\n";
