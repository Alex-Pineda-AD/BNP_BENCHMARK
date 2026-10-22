<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
$p=database();
$b=$p->query("SELECT id,descripcion FROM bancos WHERE UPPER(descripcion) LIKE '%OCCIDENTE%'")->fetchAll();
foreach($b as $banco) {
    $id=(int)$banco['ID'];
    foreach([
        'depositos'=>"SELECT id,codigo,numero_referencia,monto,fecha_deposito,estado,numero_cuenta,moneda FROM depositos WHERE id_banco=$id ORDER BY id",
        'estados'=>"SELECT id,numero_cuenta,moneda,periodo_desde,periodo_hasta,saldo_inicial,saldo_final,saldo_libros,saldo_disponible FROM estados_cuenta WHERE id_banco=$id",
        'coincidencias'=>"SELECT t.id,t.id_estado_cienta,t.fecha,t.hora,t.referencia,t.credito,t.debito,t.balance FROM transacciones_estado_cuenta t JOIN estados_cuenta e ON e.id=t.id_estado_cienta WHERE e.id_banco=$id AND t.referencia IN ('61831725','62741429') ORDER BY t.fecha,t.hora,t.id",
        'vinculos'=>"SELECT v.* FROM conciliacion_vinculos v JOIN depositos d ON d.id=v.deposito_id WHERE d.id_banco=$id",
        'cierres'=>"SELECT c.id,c.estado_cuenta_id FROM conciliacion_cierres c JOIN estados_cuenta e ON e.id=c.estado_cuenta_id WHERE e.id_banco=$id"
    ] as $nombre=>$sql) echo $nombre.': '.json_encode($p->query($sql)->fetchAll(),JSON_UNESCAPED_UNICODE)."\n";
}
echo 'periodo: '.json_encode($p->query("SELECT * FROM conciliacion_periodos WHERE estado='ABIERTO'")->fetchAll())."\n";
echo 'resumen_columnas: '.json_encode($p->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='resumen_estado_cuenta' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN))."\n";
