<?php
session_start();
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {
    header('Location: ../../index.html?sesion=requerida');
    exit;
}
require_once __DIR__ . '/../../connection/depositos_config.php';
require_once __DIR__ . '/../../connection/database.php';
require_once __DIR__ . '/../../includes/centros_venta.php';
$_SESSION['depositos_csrf'] = $_SESSION['depositos_csrf'] ?? bin2hex(random_bytes(32));
$banks = []; $centers = []; $connectionError = false;
try {
    $activeUser = database()->prepare('SELECT ID FROM usuarios WHERE ID = ? AND ESTADO = 1');
    $activeUser->execute([$_SESSION['user_id']]);
    if (!$activeUser->fetchColumn()) {
        unset($_SESSION['user_id'], $_SESSION['user_nombre'], $_SESSION['depositos_csrf']);
        header('Location: ../../index.html?sesion=requerida');
        exit;
    }
    $banks = database()->query('SELECT ID, DESCRIPCION, N_CUENTA FROM bancos WHERE ESTADO = 1 ORDER BY DESCRIPCION')->fetchAll();
    if (!empty($_SESSION['user_id'])) $centers = centrosVentaUsuario(database(), (int)$_SESSION['user_id']);
}
catch (Throwable $e) { $connectionError = true; error_log($e->getMessage()); }
function esc($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div id="app">
    <div id="sidebar" class="active"><?php include __DIR__ . '/../../includes/menu_sidebar.php'; ?></div>
    <main id="main">
        <header class="mb-3">
            <a href="#" class="burger-btn d-block d-xl-none" aria-label="Abrir menú"><i class="bi bi-justify fs-3"></i></a>
        </header>
            <div class="page-heading mb-4">
                <div>
                    <!-- <p class="text-primary mb-1">CONCILIACIÓN BANCARIA</p> -->
                    <h3>Registrar depósito</h3>
                    <p class="text-muted">Tomá una foto del comprobante, revisá los datos y guardá el registro.</p>
                </div>
                <span class="badge bg-light-primary">Captura con cámara</span>
            </div>


        <?php if ($connectionError): ?><
        div class="alert alert-danger">No se pudo conectar con la base de datos. Recargá la página cuando esté disponible.
</div><?php endif; ?>

        <div id="notice" class="alert alert-light-primary" role="status" aria-live="polite">
            Seleccioná el banco para habilitar la cámara o la carga de una foto.
        </div>

        <div class="row">
            <section class="col-xl-5">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5">1. Seleccioná el banco y cargá la foto</h2>
                    </div>
                    
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 mb-3"><label for="ID_BANCO" class="form-label">Banco *</label>
                            <select id="ID_BANCO" name="ID_BANCO" form="deposit-form" class="form-select" required>
                                <option value="">Seleccioná el banco</option>
                                <?php foreach ($banks as $bank): ?>
                                <option value="<?= esc($bank['ID']) ?>" data-cuenta="<?= esc($bank['N_CUENTA']) ?>"><?= esc($bank['DESCRIPCION'].' - '.$bank['N_CUENTA']) ?>
                                </option><?php endforeach; ?></select>
                            <p class="small text-muted">Seleccioná el banco antes de cargar la foto. Si cambiás el banco, se limpiará el comprobante anterior.</p>
                            </div>


                            <div class="col-6 mb-3">
                                <label for="TIPO_CAPTURA" class="form-label">Tipo de comprobante</label>
                                <select id="TIPO_CAPTURA" name="TIPO_CAPTURA" form="deposit-form" class="form-select" required>
                                    <option value="ocr">Impreso — lectura automática</option>
                                    <option value="manual">Ingreso manual / manuscrito</option>
                                </select>
                                <p class="small text-muted mt-2">Para manuscritos, la foto es obligatoria y los datos se ingresan manualmente. Al cambiar de tipo, cargá nuevamente la foto.</p>
                            </div>
                        </div>

                    <div class="bg-light border rounded p-4 text-center" id="preview-box">
                        <div id="empty-preview" class="py-5"><i class="bi bi-camera fs-1"></i>
                            <h3 class="h5 mt-3">Tu comprobante, aquí</h3><p>Usá buena luz y evitá reflejos.<br>Incluí el monto, la cuenta y la referencia.</p>
                        </div>
                        <img class="img-fluid rounded" id="preview" alt="Foto del comprobante capturado" hidden>
                    </div>

                    <button type="button" id="open-camera" disabled class="btn btn-primary w-100 mt-3"><i class="bi bi-camera me-2"></i>Abrir cámara</button>

                    <?php if (DEPOSITOS_PERMITIR_ARCHIVOS): ?>
                    <div class="mt-3">
                        <label for="photo-file" class="form-label">Cargar foto del depósito</label>
                        <input type="file" id="photo-file" disabled class="form-control" accept="image/jpeg,image/png,image/webp,.jfif" aria-describedby="photo-file-help">
                        <p id="photo-file-help" class="small text-muted mt-2 mb-0">JPG, JPEG/JFIF, PNG o WebP, hasta 8 MB. También podés usar la cámara.</p>
                    </div>


                    <?php endif; ?>
                    <p class="small text-muted mt-3 mb-0"><?= DEPOSITOS_PERMITIR_ARCHIVOS ? 'Podés tomar una foto o seleccionar una imagen.' : 'La foto se obtiene únicamente desde la cámara.' ?> La lectura se realiza en este dispositivo.</p>
                    <div id="ocr-progress" class="mt-3" hidden>
                        <label for="progress" id="progress-label">Preparando lectura…</label>
                        <progress id="progress" max="100" value="0" class="w-100"></progress>
                    </div>

                    <button type="button" id="rotate-photo" class="btn btn-outline-primary mt-3" disabled>Girar foto y volver a leer</button>
                    <button type="button" id="skip-ocr" class="btn btn-outline-secondary mt-3" hidden>Completar a mano sin esperar</button>
                    <details hidden class="mt-3"><summary class="small text-muted">Ver texto detectado</summary><pre id="ocr-text" class="small text-wrap mt-3"></pre></details>
                </div>
            </div>
            </section>
            <section class="col-xl-7">
                <div class="card"><div class="card-header"><h2 class="h5">2. Revisá los datos</h2><p class="text-muted mb-0">La lectura automática es opcional. Si no se extraen los datos de la foto, completalos manualmente. Los campos marcados con * son obligatorios.</p></div><div class="card-body">
                    <form id="deposit-form" data-authenticated="<?= empty($_SESSION['user_id']) ? '0' : '1' ?>" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= esc($_SESSION['depositos_csrf']) ?>">
                        <div class="border rounded p-3 mb-4">
                            <h3 class="h6">Centro de venta del depósito</h3>
                            <div class="row">
                                <div class="col-md-3 mb-3"><label for="centro-id" class="form-label">Filtrar por ID</label><input id="centro-id" class="form-control" type="text" inputmode="numeric" maxlength="19" placeholder="Ej. 5001" aria-describedby="centro-help" <?= !$centers ? 'disabled' : '' ?>></div>
                                <div class="col-md-5 mb-3">
                                    <label for="ID_CENTRO_VENTA" class="form-label">Centro de venta *</label>
                                    <div id="centro-select-wrapper" class="position-relative">
                                        <select id="ID_CENTRO_VENTA" name="ID_CENTRO_VENTA" class="form-select" required aria-describedby="centro-help" <?= !$centers ? 'disabled' : '' ?>>
                                            <option value=""></option>
                                            <?php foreach ($centers as $center): ?>
                                            <option value="<?= esc($center['ID']) ?>"><?= esc($center['ID'] . ' ' . $center['CENTRO_VENTA']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-4 mb-3">
                                    <label for="FECHA_DEPOSITO" class="form-label">Fecha y hora del comprobante *</label>
                                    <input type="datetime-local" id="FECHA_DEPOSITO" name="FECHA_DEPOSITO" class="form-control" min="1000-01-01T00:00" max="9999-12-31T23:59" required>
                                    <small class="text-muted">Ingresá la fecha y hora que aparecen en el comprobante.</small>
                                </div>

                            </div>
                            <p id="centro-help" class="small text-muted mb-0" role="status" aria-live="polite"><?= $centers ? 'Buscá por nombre o escribí el ID. Solo se muestran tus centros asignados.' : (empty($_SESSION['user_id']) ? 'Iniciá sesión para ver tus centros de venta.' : ($connectionError ? 'No se pudieron cargar los centros de venta.' : 'No tenés centros de venta activos asignados. Solicitá la asignación antes de guardar.')) ?></p>
                        </div>
                            <fieldset id="data-fields" disabled>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="NUMERO_CUENTA" class="form-label">Cuenta destino *</label>
                                        <input id="NUMERO_CUENTA" name="NUMERO_CUENTA" readonly class="form-control" maxlength="50" pattern="[0-9\/\-]+" required>
                                    </div>

                                <div class="col-12 mb-3">
                                    <label for="REMITENTE" class="form-label">Depositante / remitente *</label>
                                    <input id="REMITENTE" name="REMITENTE" class="form-control" maxlength="150" required>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="BENEFICIARIO" class="form-label">Beneficiario / titular de la cuenta *</label>
                                    <input id="BENEFICIARIO" name="BENEFICIARIO" class="form-control" maxlength="150" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label for="MONEDA" class="form-label">Moneda *</label>
                                    <select id="MONEDA" name="MONEDA" class="form-select" required>
                                        <option value="">Seleccioná</option><option value="HNL">Lempiras (HNL)</option>
                                        <option value="USD">Dólares (USD)</option>
                                    </select>
                                </div>

                                <div class="col-md-8 mb-3">
                                    <label for="MONTO" class="form-label">Monto total depositado *</label>
                                    <input type="text" inputmode="decimal" id="MONTO" required name="MONTO" class="form-control" pattern="[0-9]{1,16}(\.[0-9]{1,2})?" placeholder="Ej. 2216.00" required>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="NUMERO_REFERENCIA" class="form-label">Referencia / número de documento (si aparece)</label>
                                    <input id="NUMERO_REFERENCIA" name="NUMERO_REFERENCIA" class="form-control" maxlength="100" pattern="[A-Za-z0-9\-]+" required>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="NUMERO_AUTORIZACION" class="form-label">Autorización / aprobación (si aparece)</label>
                                    <input id="NUMERO_AUTORIZACION" name="NUMERO_AUTORIZACION" class="form-control" maxlength="100" pattern="[A-Za-z0-9\-]+" required>
                                    <p class="small text-muted mt-2">No uses identidad, terminal ni secuencia de caja como referencia. Si no aparece, dejala vacía.</p>
                                </div>

                                <div class="col-12 mb-3">
                                    <label for="COMENTARIO" class="form-label">Concepto / observaciones *</label>
                                    <textarea id="COMENTARIO" name="COMENTARIO" class="form-control" maxlength="500" rows="2" required></textarea>
                                </div>

                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="CONFIRMADO" name="CONFIRMADO" value="1" required>
                                <label class="form-check-label" for="CONFIRMADO">Comparé los datos con la foto y confirmo que son correctos.</label>
                            </div>
                        </fieldset>

                        <button type="submit" id="save" class="btn btn-primary w-100" disabled><i class="bi bi-check2-circle me-2"></i>Guardar depósito</button>
                        <p class="small text-muted mt-2 mb-0">El registro quedará pendiente de revisión.</p>
                    </form>
                </div></div>
            </section>
        </div>
        <?php include __DIR__ . '/../../includes/menu_footer.php'; ?>
    </main>
</div>
<dialog class="border-0 rounded p-0 col-11 col-lg-6" id="camera-dialog" aria-labelledby="camera-title"><div class="d-flex justify-content-between align-items-center gap-3 p-3"><h2 id="camera-title" class="h5 mb-0">Enfocá el comprobante completo</h2><button id="close-camera" class="btn btn-light" type="button" aria-label="Cerrar cámara">Cerrar</button></div><video class="w-100" id="camera-video" autoplay playsinline muted></video><p id="camera-status" role="status" class="px-3">Esperando permiso de la cámara…</p><div class="text-center p-3"><button id="capture" class="btn btn-primary" type="button" disabled>Tomar foto</button></div></dialog>
<script src="../../assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script>
<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/vendors/sweetalert2/sweetalert2.all.min.js"></script>
<script src="../../assets/js/main.js"></script>
<script src="../../assets/vendors/tesseract/tesseract.min.js"></script>
<script src="../../js/deposito-parser.js?v=<?= filemtime(__DIR__ . "/../../js/deposito-parser.js") ?>"></script>
<script src="../../js/receipt-image.js?v=<?= filemtime(__DIR__ . "/../../js/receipt-image.js") ?>"></script>
<script src="../../assets/vendors/jquery/jquery.min.js"></script>
<script src="../../assets/vendors/select2/select2.min.js"></script>
<script src="../../assets/vendors/select2/es.js"></script>
<script src="../../js/centros-venta.js"></script>
<script src="../../js/depositos.js?v=<?= filemtime(__DIR__ . "/../../js/depositos.js") ?>"></script>
</body>
</html>
