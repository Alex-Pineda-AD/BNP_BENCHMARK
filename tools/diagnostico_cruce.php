<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
require_once __DIR__.'/../includes/conciliacion_service.php';
$pdo=database();
$ds=$pdo->query('SELECT id,codigo,id_banco,estado,monto,moneda,numero_cuenta,numero_referencia,fecha_deposito FROM depositos ORDER BY id DESC LIMIT 30')->fetchAll();
$es=$pdo->query('SELECT * FROM estados_cuenta ORDER BY id DESC')->fetchAll();
foreach($ds as $d) {
    echo 'Depósito '.$d['ID'].' '.$d['CODIGO'].' estado '.$d['ESTADO'].' monto '.$d['MONTO'].' fecha '.($d['FECHA_DEPOSITO']?:'ausente')."\n";
    foreach($es as $e) {
        if((int)$e['ID_BANCO']!==(int)$d['ID_BANCO']) continue;
        $q=$pdo->prepare('SELECT * FROM transacciones_estado_cuenta WHERE id_estado_cienta=?'); $q->execute([$e['ID']]);
        $ref=$monto=$texto=$disponibles=$total=0;
        foreach($q->fetchAll() as $m) {
            $total++; $c=conciliacionCandidato($d,$m,$e); $ref+=(int)$c['referencia']; $monto+=(int)$c['monto'];
            if(!empty($d['NUMERO_REFERENCIA']) && strpos(strtoupper((string)$m['DESCRIPCION']),strtoupper($d['NUMERO_REFERENCIA']))!==false) $texto++;
        }
        echo '  Estado '.$e['ID'].' período '.$e['PERIODO_DESDE'].' / '.$e['PERIODO_HASTA'].' movimientos '.$total.' ref '.$ref.' monto '.$monto.' referencia_en_descripcion '.$texto.' cuenta_igual '.(conciliacionCuenta($d['NUMERO_CUENTA'])===conciliacionCuenta($e['NUMERO_CUENTA'])?'sí':'no')."\n";
        if(($argv[1]??'')===$d['CODIGO']) {
            foreach($e as $campo=>$valor) if(is_resource($valor)) echo '  Campo binario: '.$campo.' ('.get_resource_type($valor).")\n";
            try { conciliacionHuella([$d,$e]); echo "  Huella JSON correcta.\n"; } catch(Throwable $error) { echo '  Error reproducido: '.$error->getMessage()."\n"; }
        }
    }
}
