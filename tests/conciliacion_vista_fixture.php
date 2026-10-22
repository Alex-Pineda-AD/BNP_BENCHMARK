<?php
// CLI-only synthetic preview; never loads a real user, bank account or database.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/conciliacion_service.php';
function ce($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cruceUrl(int $eid,string $vista='cruce',int $did=0,int $mid=0): string { return 'cruce_depositos.php?'.http_build_query(['estado_cuenta'=>$eid,'vista'=>$vista,'deposito'=>$did,'movimiento'=>$mid]); }
function camposCruce(int $eid): void { echo '<input type="hidden" name="csrf" value="demo">'; }
$_SESSION=['user_id'=>1,'id_rol'=>4]; $_POST=[];
$_SERVER['SCRIPT_NAME']='/modules/conciliacion/cruce_depositos.php';
$eid=$did=$mid=1; $error=$mensaje=''; $supervisor=true; $cerrado=false; $_SERVER['REQUEST_METHOD']='GET';
$seleccion=['ID'=>1,'BANCO'=>'Banco de ejemplo','NUMERO_CUENTA'=>'001-002','MONEDA'=>'HNL','PERIODO_DESDE'=>'2026-10-01','PERIODO_HASTA'=>'2026-10-31','SALDO_INICIAL'=>'100.00','SALDO_FINAL'=>'150.00'];
$dep=['ID'=>1,'ID_BANCO'=>1,'ESTADO'=>5,'CODIGO'=>'DEMO-001','NUMERO_CUENTA'=>'001002','MONEDA'=>'HNL','MONTO'=>'50.00','NUMERO_REFERENCIA'=>'REF-001','FECHA_DEPOSITO'=>'2026-10-14 10:00:00'];
$seleccion['ID_BANCO']=1;
$m=['ID'=>1,'REFERENCIA'=>'REF-001','FECHA'=>'2026-10-14','HORA'=>'10:00:00','CREDITO'=>'50.00','DEBITO'=>'0.00','BALANCE'=>'150.00','DESCRIPCION'=>'Depósito de ejemplo','VINCULO_ESTADO'=>null,'PARTIDA'=>null];
$m['COMPARACION']=conciliacionCandidato($dep,$m,$seleccion);
$estados=[$seleccion]; $depositos=[$dep]; $movimientos=[$m]; $candidatos=[$m]; $elegido=$m; $vinculos=$cierres=[]; $foto=null; $porAprobar=0; $pendientes=1;
for($i=2;$i<=60;$i++) { $otro=$m; $otro['ID']=$i; $otro['REFERENCIA']='DEMO-'.str_pad((string)$i,3,'0',STR_PAD_LEFT); $movimientos[]=$otro; }
define('CONCILIACION_VISTA',true);
foreach(['cruce','prevalidacion','movimientos','cierre'] as $vista) {
    ob_start(); require __DIR__.'/../includes/conciliacion_vista.php'; $html=ob_get_clean();
    file_put_contents(__DIR__.'/../modules/conciliacion/.preview-'.$vista.'.html',$html);
}
echo "Previews sintéticos generados.\n";
