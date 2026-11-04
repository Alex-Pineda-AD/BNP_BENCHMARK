<!-- Modal Único para Agregar / Editar Competidor -->
<div class="modal fade" id="modalCompetidor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white" id="modalCompetidorTitulo">Nuevo Competidor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form id="formCompetidor" action="../../controllers/benchmark_controllers/benchmark_competidores_controller.php" method="POST">
                <!-- Input oculto indispensable para saber si estamos editando -->
                <input type="hidden" id="competidor_id" name="id">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(benchmark_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label for="nombre" class="form-label">Competidor (cadena) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej. La Colonia" required>
                    </div>

                    <div class="form-group mb-3">
                        <label for="sucursal" class="form-label">Sucursal</label>
                        <input type="text" class="form-control" id="sucursal" name="sucursal" maxlength="100" placeholder="Ej. Las Hadas">
                        <small class="text-muted">Déjala vacía si solo hay una tienda. La misma cadena puede registrarse varias veces con sucursales distintas.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label for="ubicacion_referencia" class="form-label">Dirección / Referencia</label>
                        <input type="text" class="form-control" id="ubicacion_referencia" name="ubicacion_referencia" placeholder="Ej. Blvd. Morazán, frente a...">
                    </div>

                    <div class="form-group mb-3">
                        <label for="estado" class="form-label">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary ml-1" id="btnGuardarCompetidor">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>