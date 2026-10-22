<div class="modal fade" id="editarDeposito" tabindex="-1" aria-labelledby="tituloEditarDeposito" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header bg-warning"><h5 class="modal-title" id="tituloEditarDeposito">Editar depósito</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <form action="../../controllers/mantenimiento_depositos.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['depositos_csrf']) ?>">
            <input type="hidden" name="bande" value="2"><input type="hidden" name="idDeposito">
            <input type="hidden" name="TIPO_CAPTURA" value="manual">
            <div class="modal-body">
                <p id="codigoDeposito" class="fw-bold"></p>
                <div id="solicitudCorreccionDeposito" class="alert alert-warning" hidden>
                    <strong>Última solicitud de corrección</strong>
                    <p id="motivoCorreccionDeposito" class="mb-1" style="white-space:pre-wrap"></p>
                    <small id="solicitanteCorreccionDeposito"></small>
                </div>
                <div class="row g-4">
                <div class="col-lg-6">
                    <div class="position-sticky" style="top:1rem">
                        <h6>Comprobante</h6>
                        <div class="border rounded bg-light p-2 mb-3" style="max-height:70vh;overflow:auto">
                            <img id="fotoDepositoActual" class="img-fluid w-100" alt="Comprobante para revisar" hidden>
                            <p id="sinFotoDeposito" class="text-muted m-3">No hay una foto disponible.</p>
                        </div>
                        <label for="nuevaFotoDeposito">Nueva foto (opcional)</label>
                        <input id="nuevaFotoDeposito" type="file" name="foto" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" aria-describedby="estadoFotoDeposito">
                        <p id="estadoFotoDeposito" class="small text-muted" role="status" aria-live="polite">Si no seleccionás otra foto, se conserva la actual. JPG, PNG o WebP, hasta 8 MB.</p>
                    </div>
                </div>
                <div class="col-lg-6">
                <h6>Datos del depósito</h6>
                <div class="row">
                    <div class="col-md-6 mb-3"><label>Banco</label><select name="ID_BANCO" class="form-select" required>
                        <?php foreach ($bancos as $banco): ?><option value="<?= esc($banco['ID']) ?>"><?= esc($banco['DESCRIPCION']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="col-md-6 mb-3"><label>Centro de venta</label>

                            <select name="ID_CENTRO_VENTA" class="form-select" required>
                            <?php foreach ($centros as $centro): ?>
                                <option value="<?= esc($centro['ID']) ?>"><?= esc($centro['ID'] . ' - ' . $centro['CENTRO_VENTA']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach (['NUMERO_CUENTA'=>['Cuenta',50], 'REMITENTE'=>['Depositante / remitente',150], 'BENEFICIARIO'=>['Beneficiario',150], 'NUMERO_REFERENCIA'=>['Referencia / número de documento',100], 'NUMERO_AUTORIZACION'=>['Autorización / aprobación',100]] as $campo=>$config): ?>
                        <div class="col-md-6 mb-3"><label><?= esc($config[0]) ?></label><input name="<?= esc($campo) ?>" maxlength="<?= $config[1] ?>" class="form-control" <?= in_array($campo, ['NUMERO_REFERENCIA','NUMERO_AUTORIZACION']) ? '' : 'required' ?>></div>
                    <?php endforeach; ?>
                    <div class="col-md-6 mb-3"><label>Monto</label><input name="MONTO" class="form-control" inputmode="decimal" pattern="[0-9]{1,16}(\.[0-9]{1,2})?" required></div>
                    <div class="col-md-6 mb-3"><label>Moneda</label><select name="MONEDA" class="form-select" required><option value="HNL">HNL</option><option value="USD">USD</option></select></div>
                    <div class="col-12 mb-3"><label>Concepto / observaciones</label><textarea name="COMENTARIO" class="form-control" maxlength="500" required></textarea></div>
                </div>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="CONFIRMADO" value="1" required> Revisé los datos y la foto. Al guardar quedará pendiente de revisión.</label>
                </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar cambios</button></div>
        </form>
    </div></div>
</div>
