<?php
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../../connection/database.php';
require_once __DIR__.'/../../includes/conciliacion_service.php';
require_once __DIR__.'/../../includes/conciliacion_reporte.php';
header('Cache-Control: no-store');
if(empty($_SESSION['user_id'])) { header('Location: ../../index.html?sesion=requerida'); exit; }
$pdo=database();
try { conciliacionUsuario($pdo,(int)$_SESSION['user_id']); } catch(Throwable $e) { http_response_code(403); exit('No tenés permiso para consultar cierres.'); }
function ch($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$error=''; $reporte=null; $cierres=[];
$banco=is_string($_GET['banco']??'')?($_GET['banco']??''):'';
$desde=is_string($_GET['desde']??'')?($_GET['desde']??''):''; $hasta=is_string($_GET['hasta']??'')?($_GET['hasta']??''):'';
$bancos=$pdo->query('SELECT id,descripcion FROM bancos ORDER BY descripcion')->fetchAll();
try {
    $id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT);
    if(isset($_GET['id']) && (!$id || $id<1)) throw new RuntimeException('Seleccioná un cierre válido.');
    if($id) $reporte=conciliacionCierre($pdo,$id);
    foreach([$desde,$hasta] as $f) if($f!=='') { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f); if(!$d || $d->format('Y-m-d')!==$f) throw new RuntimeException('Ingresá fechas válidas.'); }
    if($desde!=='' && $hasta!=='' && $desde>$hasta) throw new RuntimeException('Desde no puede ser posterior a Hasta.');
    if($banco!=='' && (!ctype_digit($banco) || (int)$banco<1)) throw new RuntimeException('Seleccioná un banco válido.');
    $sql="SELECT c.id,c.estado_cuenta_id,c.creado_en,u.nombre_completo responsable,COALESCE(c.resumen->>'excepcional','false') excepcional,
        COALESCE(c.resumen->'estado'->>'BANCO',b.descripcion) banco,
        c.resumen->'estado'->>'NUMERO_CUENTA' cuenta,c.resumen->'estado'->>'MONEDA' moneda,
        c.resumen->'estado'->>'PERIODO_DESDE' desde,c.resumen->'estado'->>'PERIODO_HASTA' hasta,
        c.resumen->'estado'->>'SALDO_INICIAL' saldo_inicial,c.resumen->'estado'->>'SALDO_FINAL' saldo_final
        FROM conciliacion_cierres c LEFT JOIN usuarios u ON u.id=c.usuario_id
        LEFT JOIN bancos b ON b.id=CAST(c.resumen->'estado'->>'ID_BANCO' AS bigint) WHERE 1=1";
    $parametros=[];
    if($banco!=='') { $sql.=" AND c.resumen->'estado'->>'ID_BANCO'=?"; $parametros[]=$banco; }
    if($desde!=='') { $sql.=" AND CAST(c.resumen->'estado'->>'PERIODO_HASTA' AS date)>=CAST(? AS date)"; $parametros[]=$desde; }
    if($hasta!=='') { $sql.=" AND CAST(c.resumen->'estado'->>'PERIODO_DESDE' AS date)<=CAST(? AS date)"; $parametros[]=$hasta; }
    $q=$pdo->prepare($sql.' ORDER BY c.creado_en DESC,c.id DESC'); $q->execute($parametros); $cierres=$q->fetchAll();
} catch(Throwable $e) { error_log($e->getMessage()); $error=$e instanceof PDOException?'No se pudieron consultar los cierres.':$e->getMessage(); }
?>
<!DOCTYPE html><html lang="es"><head><?php include __DIR__.'/../../includes/head.php'; ?><link rel="stylesheet" href="../../assets/css/pages/conciliacion.css?v=<?=filemtime(__DIR__.'/../../assets/css/pages/conciliacion.css')?>"></head><body><div id="app"><div id="sidebar" class="active"><?php include __DIR__.'/../../includes/menu_sidebar.php'; ?></div><main id="main" class="cruce-app">
<header class="cruce-heading"><div><a href="#" class="burger-btn d-xl-none" aria-label="Abrir menú">☰</a><h3>Períodos cerrados</h3><p>Consultá los cierres por banco, cuenta y período. Cada cierre corresponde a un estado de cuenta.</p></div><a class="btn btn-outline-primary" href="cruce_depositos.php?vista=cierre">Preparar cierre</a></header>
<?php if($error): ?><div class="alert alert-warning"><?=ch($error)?></div><?php endif; ?>
<section class="card"><div class="card-body"><form method="get" class="row g-3"><div class="col-md-4"><label for="banco">Banco</label><select id="banco" name="banco" class="form-select"><option value="">Todos</option><?php foreach($bancos as $b): ?><option value="<?=(int)$b['ID']?>" <?=$banco===(string)$b['ID']?'selected':''?>><?=ch($b['DESCRIPCION'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><label for="desde">Período desde</label><input id="desde" type="date" name="desde" class="form-control" value="<?=ch($desde)?>"></div><div class="col-md-3"><label for="hasta">Período hasta</label><input id="hasta" type="date" name="hasta" class="form-control" value="<?=ch($hasta)?>"></div><div class="col-md-2"><label class="d-block">Consultar</label><button class="btn btn-primary">Filtrar</button></div></form></div></section>
<section class="card"><div class="card-body cruce-table-wrap"><table class="table"><thead><tr><th>Cierre</th><th>Banco / cuenta</th><th>Período</th><th>Moneda</th><th>Saldo inicial / final</th><th>Cerrado por / fecha</th><th>Acciones</th></tr></thead><tbody><?php foreach($cierres as $c): ?><tr><td>#<?=(int)$c['ID']?><br><small><?=$c['EXCEPCIONAL']==='true'?'EXCEPCIONAL':'Normal'?></small></td><td><?=ch($c['BANCO'])?><br><?=ch($c['CUENTA'])?></td><td><?=ch($c['DESDE'])?><br><?=ch($c['HASTA'])?></td><td><?=ch($c['MONEDA'])?></td><td><?=ch($c['SALDO_INICIAL'])?><br><?=ch($c['SALDO_FINAL'])?></td><td><?=ch($c['RESPONSABLE'])?><br><?=ch($c['CREADO_EN'])?></td><td><a class="btn btn-outline-primary btn-sm" href="?id=<?=(int)$c['ID']?>#detalle">Ver detalle</a> <a class="btn btn-outline-primary btn-sm" href="../../controllers/exportar_cierre_conciliacion.php?id=<?=(int)$c['ID']?>">Exportar Excel</a></td></tr><?php endforeach; if(!$cierres): ?><tr><td colspan="7">No hay cierres para los filtros seleccionados. Un cruce prevalidado o aprobado aparece aquí después de cerrar su estado de cuenta.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if($reporte): ?><section class="card" id="detalle"><div class="card-header cruce-heading"><h5>Detalle del cierre #<?=(int)$reporte['cierre']['ID']?></h5><a class="btn btn-primary" href="../../controllers/exportar_cierre_conciliacion.php?id=<?=(int)$reporte['cierre']['ID']?>">Exportar Excel (.xlsx)</a></div><div class="card-body"><p>Datos conservados al momento del cierre. Los montos se presentan en <?=ch($reporte['estado']['MONEDA'])?>.</p><div class="cruce-table-wrap"><table class="table"><tbody><?php foreach($reporte['resumen'] as $fila): ?><tr><th><?=ch($fila[0])?></th><td><?=ch($fila[1])?></td></tr><?php endforeach; ?></tbody></table></div><h6>Movimientos, vínculos y justificaciones</h6><div class="cruce-table-wrap"><table class="table"><thead><tr><?php foreach($reporte['cabeceras'] as $cabecera): ?><th><?=ch($cabecera)?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($reporte['filas'] as $fila): ?><tr><?php foreach($fila as $dato): ?><td><?=ch($dato)?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>
<?php include __DIR__.'/../../includes/menu_footer.php'; ?></main></div><script src="../../assets/js/bootstrap.bundle.min.js"></script><script src="../../assets/js/main.js"></script></body></html>
