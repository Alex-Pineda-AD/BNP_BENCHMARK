<!-- Modal de Registro Dinámico por Filas -->
<div class="modal fade" id="modalLevantamiento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white" id="modalLevantamientoTitulo">
                    <i class="bi bi-file-earmark-spreadsheet"></i> &nbsp; Nuevo Levantamiento y Comparativa de Precios
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form id="formLevantamiento" action="../../controllers/benchmark_controllers/benchmark_levantamientos_controller.php" method="POST">
                <div class="modal-body">
                    <!-- Datos de la Cabecera -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label for="id_competidor" class="form-label">Competidor <span class="text-danger">*</span></label>
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
                        <div class="col-md-3">
                            <label for="fecha_levantamiento" class="form-label">Fecha de Inspección <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" id="fecha_levantamiento" name="fecha_levantamiento" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="observaciones" class="form-label">Observaciones Generales</label>
                            <input type="text" class="form-control form-control-sm" id="observaciones" name="observaciones" placeholder="Ej. Visita a sucursal principal">
                        </div>
                    </div>

                    <hr class="my-2">
                    
                    <!-- Botón para agregar nueva línea -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-primary mb-0" style="font-size: 0.9rem;">
                            <i class="bi bi-list-check"></i> Detalle de Productos Encontrados
                        </h6>
                        <button type="button" class="btn btn-sm btn-success" onclick="agregarFilaProducto()">
                            <i class="bi bi-plus-circle"></i> Agregar Producto
                        </button>
                    </div>

                    <!-- Contenedor Dinámico de Filas -->
                    <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                        <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width: 32%;">Producto Banasupro (Softland)</th>
                                    <th style="width: 25%;">Nombre en la Competencia</th>
                                    <th style="width: 15%;">Precio Competencia</th>
                                    <th style="width: 20%;">Observaciones</th>
                                    <th style="width: 8%;" class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="contenedorFilas">
                                <!-- Las filas se insertarán aquí dinámicamente con JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary ml-1" id="btnGuardarLevantamiento">
                        <i class="fas fa-save"></i> Guardar Comparativo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Template JSON oculto con los 30 productos de Banasupro seguros para JavaScript -->
<script id="jsonProductos" type="application/json">
    <?= json_encode($productosSoftland, JSON_UNESCAPED_UNICODE) ?>
</script>