<?php
require_once __DIR__ . '/alertas_depositos.php';
// Panel administrativo: seguimiento de todos los depositos, separado del listado personal.
try {
    $rolAlertas = rolAlertasDepositos($pdo, $pantallaAlertas, (int) $_SESSION['user_id']);
    if (!$rolAlertas) return;
    $puedeConfigurarAlertas = in_array($rolAlertas, [4,5], true);
    $alertasSeguimiento = consultarAlertasDepositos($pdo, $pantallaAlertas, (int) $_SESSION['user_id']);
    $reglasActivas = $pdo->prepare('SELECT COUNT(*) FROM alertas_configuracion WHERE PANTALLA = ? AND ESTADO = 1');
    $reglasActivas->execute([$pantallaAlertas]);
    $cantidadReglas = (int) $reglasActivas->fetchColumn();
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo '<div class="alert alert-warning">No se pudo consultar el seguimiento de alertas.</div>';
    return;
}
?>
<div class="card border <?= $alertasSeguimiento ? 'border-warning' : '' ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <h5 class="mb-2">Seguimiento de atrasos: <?= count($alertasSeguimiento) ?></h5>
            <?php if ($puedeConfigurarAlertas): ?><a href="configuracion_alertas.php" class="btn btn-outline-primary btn-sm">Configurar alertas</a><?php endif; ?>
        </div>
        <p class="small text-muted"><?= $pantallaAlertas === 'DEPOSITOS' && !$puedeConfigurarAlertas ? 'Seguimiento de tus depósitos.' : 'Seguimiento de todos los depósitos.' ?> <!-- Independiente de los filtros. El plazo empieza al entrar al estado actual, no en la fecha original del depósito. Se actualiza cada minuto. Última consulta -->: <?= esc((new DateTimeImmutable('now', new DateTimeZone('America/Tegucigalpa')))->format('d/m/Y H:i:s')) ?>.</p>
        <?php if (!$cantidadReglas): ?>
            <p class="mb-0">No hay reglas activas para esta pantalla. El administrador debe configurar los plazos para iniciar el seguimiento.</p>
        <?php elseif (!$alertasSeguimiento): ?>
            <p class="mb-0">No hay atrasos con las reglas activas y las fechas de etapa disponibles en la bitácora.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>DEPÓSITO</th><th>CENTRO</th><th>ETAPA</th><th>VENCIMIENTO</th><th>PLAZO</th><th>ALERTA</th></tr></thead><tbody>
                <?php foreach ($alertasSeguimiento as $alerta): ?>
                    <tr><td><?= esc($alerta['CODIGO'] ?: '#' . $alerta['ID']) ?></td><td><?= esc($alerta['CENTRO_VENTA']) ?></td><td><?= esc($alerta['ESTADO_NOMBRE']) ?></td>
                        <td><?= esc($alerta['VENCIMIENTO']) ?></td><td><?= esc($alerta['PLAZO_DIAS']) ?> días hábiles</td><td><?= esc($alerta['MENSAJE']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
