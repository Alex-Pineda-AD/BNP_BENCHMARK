<?php
session_start();
header('Cache-Control: no-store');
require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/alertas_depositos.php';
if (empty($_SESSION['user_id'])) { header('Location: ../../index.html'); exit; }
$pdo = database();
if (!administrarAlertas($pdo, (int) $_SESSION['user_id'])) { http_response_code(403); exit('No tenés permiso para configurar alertas.'); }
function esc($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$_SESSION['alertas_token'] = $_SESSION['alertas_token'] ?? bin2hex(random_bytes(32));
$estados = $pdo->query('SELECT ID, DESCRIPCION FROM estados ORDER BY ID')->fetchAll();
$reglas = $pdo->query('SELECT A.*, E.DESCRIPCION FROM alertas_configuracion A INNER JOIN estados E ON E.ID=A.ID_ESTADO ORDER BY A.PANTALLA, A.ID_ESTADO')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div id="app">
    <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
    <main id="main">
        <header class="mb-3"><a href="#" class="burger-btn d-block d-xl-none"><i class="bi bi-justify fs-3"></i></a></header>
        <h3>Configuración de alertas</h3>
        <p>Definí el plazo por estado y pantalla. Se cuentan días hábiles (lunes a viernes) desde la entrada a la etapa, conservando la hora. No se excluyen feriados.</p>
        <?php if (isset($_SESSION['alerta_mensaje'])): ?>
            <div class="alert alert-<?= esc($_SESSION['alerta_tipo']) ?>"><?= esc($_SESSION['alerta_mensaje']) ?></div>
            <?php unset($_SESSION['alerta_mensaje'], $_SESSION['alerta_tipo']); ?>
        <?php endif; ?>
        <div class="card"><div class="card-body">
            <form id="formAlerta" action="../../controllers/configuracion_alertas.php" method="POST">
                <input type="hidden" name="token" value="<?= esc($_SESSION['alertas_token']) ?>">
                <input type="hidden" name="bande" value="1">
                <div class="row">
                    <div class="col-md-4 mb-3"><label for="pantallaAlerta">Pantalla</label>
                        <select id="pantallaAlerta" name="pantalla" class="form-select" required>
                            <option value="DEPOSITOS">Mis depósitos</option><option value="VALIDACION">Validar depósitos</option>
                        </select>
                    </div>
                    <div class="col-md-5 mb-3"><label for="estadoAlerta">Estado / etapa</label>
                        <select id="estadoAlerta" name="idEstado" class="form-select" required>
                            <option value="">Seleccioná</option>
                            <?php foreach ($estados as $estado): ?><option value="<?= esc($estado['ID']) ?>"><?= esc($estado['DESCRIPCION']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3"><label for="diasAlerta">Plazo en días hábiles</label><input id="diasAlerta" name="plazoDias" type="number" min="1" max="3650" step="1" class="form-control" required></div>
                    <div class="col-12 mb-3"><label for="mensajeAlerta">Mensaje de seguimiento</label><input id="mensajeAlerta" name="mensaje" class="form-control" maxlength="250" required></div>
                    <div class="col-12 mb-3"><label><input type="checkbox" name="activo" value="1"> Alerta activa</label></div>
                </div>
                <p class="small text-muted">Si ya existe una regla para esa pantalla y estado, se actualizará. Desmarcá “Alerta activa” para desactivarla.</p>
                <button class="btn btn-primary" type="submit">Guardar configuración</button>
                <button class="btn btn-light-secondary" type="reset">Limpiar formulario</button>
            </form>
        </div></div>
        <div class="card"><div class="card-body table-responsive">
            <table class="table table-striped"><thead><tr><th>PANTALLA</th><th>ESTADO</th><th>DÍAS HÁBILES</th><th>MENSAJE</th><th>ACTIVA</th><th>ACCIÓN</th></tr></thead><tbody>
            <?php foreach ($reglas as $regla): ?>
                <tr><td><?= $regla['PANTALLA'] === 'DEPOSITOS' ? 'Mis depósitos' : 'Validar depósitos' ?></td><td><?= esc($regla['DESCRIPCION']) ?></td>
                    <td><?= esc($regla['PLAZO_DIAS'] ?? 'Sin configurar') ?></td><td><?= esc($regla['MENSAJE']) ?></td><td><?= $regla['ESTADO'] ? 'Sí' : 'No' ?></td>
                    <td><button type="button" class="btn btn-warning btn-sm" data-regla="<?= esc(json_encode($regla, JSON_UNESCAPED_UNICODE)) ?>">Configurar</button></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div></div>
        <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
    </main>
</div>
<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
document.querySelectorAll('[data-regla]').forEach(function (boton) {
    boton.addEventListener('click', function () {
        var regla = JSON.parse(this.dataset.regla);
        var formulario = document.getElementById('formAlerta');
        formulario.elements.pantalla.value = regla.PANTALLA;
        formulario.elements.idEstado.value = regla.ID_ESTADO;
        formulario.elements.plazoDias.value = regla.PLAZO_DIAS || '';
        formulario.elements.mensaje.value = regla.MENSAJE;
        formulario.elements.activo.checked = Number(regla.ESTADO) === 1;
        formulario.scrollIntoView({behavior: 'smooth'});
    });
});
</script>
</body>
</html>
