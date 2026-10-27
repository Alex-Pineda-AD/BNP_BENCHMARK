<!-- Modal de Registro Dinámico Responsivo (Footer Fijo para Móvil y PC) -->
<style>
/* Fijar footer y permitir scroll natural sin perder el botón de guardar */
#modalLevantamiento .modal-dialog {
    display: flex;
    flex-direction: column;
    height: calc(100% - 1rem);
    max-height: 92vh;
}
#modalLevantamiento .modal-content {
    display: flex;
    flex-direction: column;
    max-height: 100%;
    overflow: hidden;
    border-radius: 12px;
}
#modalLevantamiento .modal-header {
    flex: 0 0 auto;
}
#modalLevantamiento .modal-body {
    flex: 1 1 auto;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
#modalLevantamiento .modal-footer {
    flex: 0 0 auto;
    background: #ffffff;
    border-top: 1px solid #e9ecef;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.06);
    z-index: 1050;
}
</style>

<div class="modal fade" id="modalLevantamiento" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form id="formLevantamiento" action="../../controllers/benchmark_controllers/benchmark_levantamientos_controller.php" method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title text-white fs-6 mb-0" id="modalLevantamientoTitulo">
                    <i class="fas fa-clipboard-list"></i> &nbsp; Nuevo Levantamiento y Comparativa
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-3">
                <!-- Datos de la Cabecera -->
                <div class="card bg-light border-0 mb-3 shadow-none">
                    <div class="card-body p-2">
                        <div class="row g-2">
                            <div class="col-12 col-md-5">
                                <label for="id_competidor" class="form-label small fw-bold mb-1">Competidor <span class="text-danger">*</span></label>
                                <select class="form-select form-select-sm" id="id_competidor" name="id_competidor" required>
                                    <option value="">-- Seleccionar Competidor --</option>
                                    <?php if (!empty($competidores)): ?>
                                        <?php foreach ($competidores as $comp): 
                                            $idComp = $comp['id'] ?? $comp['ID'] ?? 0;
                                            $nombreComp = $comp['nombre'] ?? $comp['NOMBRE'] ?? '';
                                        ?>
                                            <option value="<?= $idComp ?>"><?= escapar($nombreComp) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label for="fecha_levantamiento" class="form-label small fw-bold mb-1">Fecha Inspección <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="fecha_levantamiento" name="fecha_levantamiento" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4">
                                <label for="observaciones" class="form-label small fw-bold mb-1">Observaciones Visita</label>
                                <input type="text" class="form-control form-control-sm" id="observaciones" name="observaciones" placeholder="Ej. Sucursal principal">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Etiqueta de sección (sin botón) -->
                <div class="px-1 mb-2">
                    <h6 class="text-primary mb-0 small fw-bold">
                        <i class="fas fa-basket-shopping"></i> Productos a Comparar
                        <span class="badge bg-light-primary text-primary ms-1" id="contadorFilas">0</span>
                    </h6>
                </div>

                <!-- Contenedor Dinámico de Tarjetas Responsivas -->
                <div id="contenedorFilas" class="d-flex flex-column gap-2">
                    <!-- Las tarjetas de cada producto se generarán dinámicamente aquí -->
                </div>
            </div>

            <!-- Footer Siempre Visible y Fijo al Pie (3 botones: Cancelar | + Agregar | Guardar) -->
            <div class="modal-footer py-2 px-3">
                <div class="d-flex w-100 justify-content-between align-items-center gap-2">
                    <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-success" onclick="agregarFilaProducto()" id="btnAgregarFila">
                        <i class="fas fa-plus-circle"></i> Agregar Producto
                    </button>
                    <button type="submit" class="btn btn-primary ml-1" id="btnGuardarLevantamiento">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Template JSON con los 30 productos de Banasupro seguros para JavaScript -->
<script id="jsonProductos" type="application/json">
    <?= json_encode($productosSoftland, JSON_UNESCAPED_UNICODE) ?>
</script>