<!-- Modal Competidor -->
<div class="modal fade" id="modalCompetidor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCompetidorTitulo">Nuevo Competidor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formCompetidor">
                <div class="modal-body">
                    <input type="hidden" id="competidor_id" name="id">
                    
                    <div class="form-group mb-3">
                        <label for="nombre" class="form-label">Nombre del Competidor <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej. Supermercado X" required>
                    </div>

                    <div class="form-group mb-3">
                        <label for="ubicacion_referencia" class="form-label">Ubicación / Referencia</label>
                        <input type="text" class="form-control" id="ubicacion_referencia" name="ubicacion_referencia" placeholder="Ej. Sucursal Blvd. Morazán">
                    </div>

                    <div class="form-group mb-3">
                        <label for="estado" class="form-label">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
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