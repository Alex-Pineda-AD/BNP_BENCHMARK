<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../connection/database.php';
require_once __DIR__.'/../includes/lectores/occidente.php';
$ruta=$argv[1]??''; if(!$ruta || !is_dir(dirname($ruta)) || file_exists($ruta)) throw new RuntimeException('Indicá un archivo TXT nuevo.');
$p=database();
$q=$p->query("SELECT e.* FROM estados_cuenta e JOIN bancos b ON b.id=e.id_banco WHERE UPPER(b.descripcion) LIKE '%OCCIDENTE%' AND EXISTS(SELECT 1 FROM transacciones_estado_cuenta t WHERE t.id_estado_cienta=e.id AND t.referencia='61831725') ORDER BY e.id DESC LIMIT 1"); $e=$q->fetch();
if(!$e) throw new RuntimeException('No se encontró el estado para preparar el TXT antes del vaciado.');
$q=$p->prepare("SELECT t.*,e.saldo_inicial+SUM(t.credito-t.debito) OVER(ORDER BY t.fecha,t.hora NULLS LAST,t.id) saldo_demo FROM transacciones_estado_cuenta t JOIN estados_cuenta e ON e.id=t.id_estado_cienta WHERE e.id=? AND t.referencia IN ('61831725','62741429') ORDER BY t.fecha,t.hora NULLS LAST,t.id"); $q->execute([$e['ID']]); $ms=$q->fetchAll();
if(count($ms)!==2 || count(array_unique(array_column($ms,'REFERENCIA')))!==2) throw new RuntimeException('Se necesitan las dos referencias únicas de demostración.');
$q=$p->prepare("SELECT SUM(credito) creditos,SUM(debito) debitos,COUNT(*) FILTER(WHERE credito>0) n_creditos,COUNT(*) FILTER(WHERE debito>0) n_debitos FROM transacciones_estado_cuenta WHERE id_estado_cienta=? AND referencia IN ('61831725','62741429')"); $q->execute([$e['ID']]); $tot=$q->fetch();
function celdaDemo($v): string { return str_replace(["|","\r","\n"],[' ',' ',' '],(string)$v); }
function fechaDemo(string $v): string { return (new DateTimeImmutable($v))->format('d-m-Y'); }
$lineas=[
    'Cuenta|'.celdaDemo($e['NUMERO_CUENTA']).'|DEMOSTRACION - DOS MOVIMIENTOS COINCIDENTES',
    'Producto|CUENTA DE CHEQUES|Desde|'.fechaDemo($e['PERIODO_DESDE']).'|Hasta|'.fechaDemo($e['PERIODO_HASTA']),
    'Moneda|'.celdaDemo($e['MONEDA']).'|Saldo Inicial|'.$e['SALDO_INICIAL'].'|Saldo Final|'.$ms[1]['SALDO_DEMO'],
    'Fecha|Hora|Agen|Cajero|Trx|Ref|Descripción|Débito|Crédito|Saldo'
];
foreach($ms as $m) {
    $lineas[]=implode('|',array_map('celdaDemo',[fechaDemo($m['FECHA']),$m['HORA'],$m['AGENCIA']??'',$m['CAJERO']??'',$m['CODIGO']??'',$m['REFERENCIA'],$m['DESCRIPCION'],$m['DEBITO'],$m['CREDITO'],$m['SALDO_DEMO']]));
}
$lineas=array_merge($lineas,['Resumen de Movimientos del Mes','Descripción|N° Trans.|Monto','Débitos|'.$tot['N_DEBITOS'].'|'.$tot['DEBITOS'],'Créditos|'.$tot['N_CREDITOS'].'|'.$tot['CREDITOS'],'Cheques Pagados|0|0.00']);
$texto=implode("\r\n",$lineas)."\r\n";
$validado=leerOccidente($texto);
if(count($validado['transacciones'])!==2 || $validado['advertencias']) throw new RuntimeException('El TXT no pasó la validación del importador.');
if($validado['cuenta']['SALDO_FINAL']!==$ms[1]['SALDO_DEMO']) throw new RuntimeException('El saldo final no coincide.');
$f=fopen($ruta,'xb'); if(!$f) throw new RuntimeException('No se pudo guardar el TXT.'); try { if(fwrite($f,$texto)!==strlen($texto)) throw new RuntimeException('Escritura incompleta.'); } finally { fclose($f); }
echo 'TXT validado: '.$ruta."\n"; foreach($ms as $m) echo $m['REFERENCIA'].' | '.$m['FECHA'].' '.$m['HORA'].' | '.$m['CREDITO'].' | saldo '.$m['SALDO_DEMO']."\n";
