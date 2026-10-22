<?php
function conciliacionCierre(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT c.*,u.nombre_completo responsable FROM conciliacion_cierres c LEFT JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?'); $q->execute([$id]); $c=$q->fetch();
    if(!$c) throw new RuntimeException('No se encontró el cierre solicitado.');
    $s=json_decode($c['RESUMEN'],true,512,JSON_THROW_ON_ERROR);
    return conciliacionReporte($c,$s);
}
function conciliacionDinero(int $centavos): string {
    return ($centavos<0?'-':'').intdiv(abs($centavos),100).'.'.str_pad((string)(abs($centavos)%100),2,'0',STR_PAD_LEFT);
}
function conciliacionReporte(array $c,array $s): array {
    $e=$s['estado']; $depositos=$usuarios=$vinculos=$partidas=[];
    foreach($s['depositos']??[] as $d) $depositos[$d['ID']]=$d;
    foreach($s['usuarios']??[] as $u) $usuarios[$u['ID']]=$u['NOMBRE_COMPLETO'];
    foreach($s['vinculos']??[] as $v) if(in_array($v['ESTADO'],['APROBADO','CERRADO'],true)) $vinculos[$v['MOVIMIENTO_ID']]=$v;
    foreach($s['partidas']??[] as $p) $partidas[$p['MOVIMIENTO_ID']]=$p;
    $filas=[]; $credito=$debito=0;
    foreach($s['movimientos'] as $m) {
        $v=$vinculos[$m['ID']]??null; $p=$partidas[$m['ID']]??null; $d=$v?($depositos[$v['DEPOSITO_ID']]??[]):[];
        $credito+=(int)str_replace('.','',conciliacionDecimal($m['CREDITO']));
        $debito+=(int)str_replace('.','',conciliacionDecimal($m['DEBITO']));
        $filas[]=[(string)$m['ID'],$m['FECHA'],$m['HORA']??'',$m['REFERENCIA']??'',$m['DESCRIPCION']??'',
            $m['DEBITO'],$m['CREDITO'],$m['BALANCE'],$v?'Vínculo aprobado':($p?'Partida justificada':'Sin clasificación guardada'),
            $d['CODIGO']??($v?'ID '.$v['DEPOSITO_ID']:''),$d['NUMERO_REFERENCIA']??'',$d['FECHA_DEPOSITO']??'',$d['MONTO']??'',
            $v?($usuarios[$v['CREADO_POR']]??'Usuario #'.$v['CREADO_POR']):'', $v?($usuarios[$v['REVISADO_POR']]??'Usuario #'.$v['REVISADO_POR']):($p?($usuarios[$p['USUARIO_ID']]??'Usuario #'.$p['USUARIO_ID']):''),
            $v?($v['REVISADO_EN']??''):($p['CREADO_EN']??''),$v?($v['OBSERVACION']??''):($p['MOTIVO']??''),$v['MOTIVO']??''];
    }
    $resumen=[['Cierre',(string)$c['ID']],['Estado de cuenta',(string)$c['ESTADO_CUENTA_ID']],['Banco',$e['BANCO']??'No guardado en cierre anterior'],['Cuenta',$e['NUMERO_CUENTA']??''],['Moneda',$e['MONEDA']??''],['Período desde',$e['PERIODO_DESDE']??''],['Período hasta',$e['PERIODO_HASTA']??''],['Fecha de cierre',$c['CREADO_EN']],['Responsable',$usuarios[$c['USUARIO_ID']]??$c['RESPONSABLE']??'Usuario #'.$c['USUARIO_ID']],['Saldo inicial',$e['SALDO_INICIAL']],['Total débitos',conciliacionDinero($debito)],['Total créditos',conciliacionDinero($credito)],['Saldo final',$e['SALDO_FINAL']],['Movimientos',(string)count($filas)],['Vínculos aprobados',(string)count($vinculos)],['Partidas justificadas',(string)count($partidas)]];
    $resumen[]=['Tipo de cierre',!empty($s['excepcional'])?'EXCEPCIONAL CON AJUSTES / EXCEPCIONES':'Normal'];
    $resumen[]=['Mes de control',$s['periodo']??'No guardado'];
    $resumen[]=['Diferencia original',$s['diferencia_original']??'No guardada'];
    $resumen[]=['Total ajustes',$s['total_ajustes']??'0.00'];
    $resumen[]=['Balances inconsistentes',(string)($s['balances_inconsistentes']??0)];
    $resumen[]=['Motivo excepcional',$s['motivo_excepcional']??''];
    foreach($s['ajustes']??[] as $a) {
        $negativo=strpos($a['IMPORTE'],'-')===0; $importe=ltrim($a['IMPORTE'],'-');
        $filas[]=['AJUSTE-'.$a['ID'],$a['FECHA'],'',$a['DOCUMENTO'],$a['MOTIVO'],$negativo?$importe:'0.00',$negativo?'0.00':$importe,'','AJUSTE DE CONCILIACIÓN (no es movimiento bancario)','','','','','',$usuarios[$a['USUARIO_ID']]??'Usuario #'.$a['USUARIO_ID'],$a['CREADO_EN'],$a['MOTIVO'],$a['DOCUMENTO']];
    }
    return ['cierre'=>$c,'estado'=>$e,'resumen'=>$resumen,'cabeceras'=>['Movimiento / ajuste','Fecha banco / ajuste','Hora banco','Referencia banco / documento','Descripción','Débito','Crédito','Balance','Tratamiento','Depósito','Referencia depósito','Fecha y hora depósito','Monto depósito','Prevalidado por','Aprobado / justificado por','Fecha de decisión','Observación / justificación','Comentario del supervisor'],'filas'=>$filas];
}
