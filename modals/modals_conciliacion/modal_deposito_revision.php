<div class="modal fade" id="revisarDeposito" tabindex="-1" aria-labelledby="tituloRevisarDeposito" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header bg-warning"><h5 class="modal-title" id="tituloRevisarDeposito">Pre-validar depósito</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <form action="../../controllers/revision_depositos.php" method="POST">
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['depositos_csrf']) ?>">
            <input type="hidden" name="bande" value="3"><input type="hidden" name="idDeposito">
            <input type="hidden" name="version">
            <div class="modal-body">
                <p id="codigoDeposito" class="fw-bold"></p>
                <div class="row g-4">
                <div class="col-lg-6">
                    <div class="position-sticky" style="top:1rem">
                        <h6>Comprobante</h6>
                        <div class="border rounded bg-light p-2 mb-3" style="max-height:70vh;overflow:auto">
                            <img id="fotoDepositoActual" class="img-fluid w-100" alt="Comprobante para revisar" hidden>
                            <p id="sinFotoDeposito" class="text-muted m-3">No hay una foto disponible.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                <h6>Datos del depósito</h6>
                <p class="small text-muted">Podés corregir los datos aquí o seleccionar Por corregir e indicar qué debe modificar el usuario.</p>
                <div class="row">
                    <div class="col-md-6 mb-3"><label>Banco</label><select name="ID_BANCO" class="form-select" required><?php foreach ($bancos as $banco): ?><option value="<?= esc($banco['ID']) ?>"><?= esc($banco['DESCRIPCION']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6 mb-3"><label>Centro de venta</label><select name="ID_CENTRO_VENTA" class="form-select" required><?php foreach ($centros as $centro): ?><option value="<?= esc($centro['ID']) ?>"><?= esc($centro['CENTRO_VENTA']) ?></option><?php endforeach; ?></select></div>
                    <?php foreach (['NUMERO_CUENTA'=>['Cuenta',50], 'REMITENTE'=>['Depositante / remitente',150], 'BENEFICIARIO'=>['Beneficiario',150], 'NUMERO_REFERENCIA'=>['Referencia / número de documento',100], 'NUMERO_AUTORIZACION'=>['Autorización / aprobación',100]] as $campo=>$config): ?>
                        <div class="col-md-6 mb-3"><label><?= esc($config[0]) ?></label><input name="<?= esc($campo) ?>" maxlength="<?= $config[1] ?>" class="form-control"></div>
                    <?php endforeach; ?>
                    <div class="col-md-6 mb-3"><label>Monto</label><input name="MONTO" class="form-control" inputmode="decimal" pattern="[0-9]{1,16}(\.[0-9]{1,2})?" required></div>
                    <div class="col-md-6 mb-3"><label>Moneda</label><select name="MONEDA" class="form-select"><option value="HNL">HNL</option><option value="USD">USD</option></select></div>
                    <div class="col-12 mb-3"><label>Concepto / observaciones</label><textarea name="COMENTARIO" class="form-control" maxlength="500"></textarea></div>
                </div>
                <p>Estado actual: <strong id="estadoActualDeposito"></strong></p>
                <div class="mb-3">
                    <label for="estadoRevision" class="form-label">Estado de seguimiento *</label>
                    <select id="estadoRevisions" name="estado" class="form-select" required>
                        <option value=""></option>
                        <?php foreach ($estadosRevision ?? [] as $estado): ?>
                            <option value="<?= esc($estado['ID']) ?>"><?= esc($estado['DESCRIPCION']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="observacionRevision" class="form-label">Observación / motivo de corrección</label>
                    <textarea id="observacionRevision" name="observacion" class="form-control" maxlength="500" rows="3"></textarea>
                    <p class="small text-muted">Obligatorio cuando solicitás corregir el depósito.</p>
                </div>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="CONFIRMADO" value="1" required> Comparé la información de la foto con los datos del depósito.</label>
                </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar revisión</button></div>
        </form>
    </div></div>
</div>
