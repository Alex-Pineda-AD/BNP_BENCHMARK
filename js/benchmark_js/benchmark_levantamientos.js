let productosGlobal = [];

document.addEventListener('DOMContentLoaded', function () {
    const jsonEl = document.getElementById('jsonProductos');
    if (jsonEl) {
        try {
            productosGlobal = JSON.parse(jsonEl.textContent.trim());
        } catch (e) {
            console.error("Error al parsear los productos JSON:", e);
            productosGlobal = [];
        }
    }

    const modalEl = document.getElementById('modalLevantamiento');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function () {
            const contenedor = document.getElementById('contenedorFilas');
            if (contenedor && contenedor.children.length === 0) {
                agregarFilaProducto();
            }
        });
    }
});

function abrirModalLevantamiento() {
    const form = document.getElementById('formLevantamiento');
    if (form) form.reset();

    const contenedor = document.getElementById('contenedorFilas');
    if (contenedor) {
        contenedor.innerHTML = '';
        agregarFilaProducto();
    }

    const modalEl = document.getElementById('modalLevantamiento');
    if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function agregarFilaProducto() {
    const contenedor = document.getElementById('contenedorFilas');
    if (!contenedor) return;

    let opcionesHtml = '<option value="">-- Seleccionar Artículo Banasupro (30 clave) --</option>';
    if (Array.isArray(productosGlobal) && productosGlobal.length > 0) {
        productosGlobal.forEach(p => {
            let idp = p.id || p.ID;
            let cod = (p.codigo || p.CODIGO) ? `[${p.codigo || p.CODIGO}] ` : '';
            let nombrep = p.nombre_producto || p.NOMBRE_PRODUCTO || '';
            let gramaje = (p.gramaje || p.GRAMAJE) ? ` (${p.gramaje || p.GRAMAJE})` : '';
            let precio = parseFloat(p.precio_referencia || p.PRECIO_REFERENCIA || 0).toFixed(2);
            opcionesHtml += `<option value="${idp}" data-precio="${precio}">${cod}${nombrep}${gramaje} • L. ${precio}</option>`;
        });
    }

    const card = document.createElement('div');
    card.className = 'card border mb-2 shadow-sm fila-producto';
    card.innerHTML = `
        <div class="card-header py-1 px-3 bg-light d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-secondary">
                <i class="bi bi-tag-fill text-primary"></i> <span class="numero-item">Item</span>
            </span>
            <button type="button" class="btn btn-danger btn-sm py-0 px-2" onclick="eliminarFila(this)" title="Quitar este producto">
                <i class="fas fa-trash-alt"></i> Quitar
            </button>
        </div>
        <div class="card-body p-2">
            <!-- 1. Artículo Banasupro -->
            <div class="mb-2">
                <label class="form-label small fw-bold mb-1 text-muted">Artículo Banasupro <span class="text-danger">*</span></label>
                <select name="id_producto[]" class="form-select form-select-sm select-articulo" required onchange="actualizarFilaSoftland(this)">
                    ${opcionesHtml}
                </select>
            </div>

            <!-- 2. Comparativa de Precios en dos columnas -->
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small fw-bold mb-1 text-muted">Precio Banasupro</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light">L.</span>
                        <input type="text" class="form-control form-control-sm bg-light text-end fw-bold label-precio-bana" readonly value="0.00">
                        <input type="hidden" name="precio_banasupro_ref[]" class="input-precio-bana" value="0.00">
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold mb-1 text-danger">Precio Competidor *</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white">L.</span>
                        <input type="number" step="0.01" min="0" name="precio_competencia[]" class="form-control form-control-sm text-end fw-bold input-precio-comp" placeholder="0.00" required oninput="calcularDiferenciaFila(this)">
                    </div>
                </div>
            </div>

            <!-- 3. Indicador de Diferencia -->
            <div class="d-flex justify-content-between align-items-center mb-2 px-2 bg-light rounded py-1">
                <span class="small text-muted" style="font-size: 0.78rem;">Comparativa:</span>
                <span class="badge bg-secondary badge-diferencia">Pendiente de precio</span>
            </div>

            <!-- 4. Observaciones opcionales -->
            <div>
                <input type="text" name="observaciones_item[]" class="form-control form-control-sm" placeholder="Observaciones / Notas (Opcional)">
            </div>
        </div>
    `;

    contenedor.appendChild(card);
    renumerarItems();
}

function eliminarFila(btn) {
    const card = btn.closest('.fila-producto');
    const contenedor = card.parentElement;
    card.remove();
    renumerarItems();
    if (contenedor && contenedor.children.length === 0) {
        agregarFilaProducto();
    }
}

function renumerarItems() {
    const items = document.querySelectorAll('.fila-producto .numero-item');
    items.forEach((span, index) => {
        span.textContent = `Producto #${index + 1}`;
    });
    // Actualizar el badge contador en el modal
    const contador = document.getElementById('contadorFilas');
    if (contador) contador.textContent = items.length;
}

function actualizarFilaSoftland(selectEl) {
    const card = selectEl.closest('.fila-producto');
    const opt = selectEl.options[selectEl.selectedIndex];
    const precio = parseFloat(opt.getAttribute('data-precio') || 0).toFixed(2);

    const labelBana = card.querySelector('.label-precio-bana');
    const inputBana = card.querySelector('.input-precio-bana');
    if (labelBana) labelBana.value = precio;
    if (inputBana) inputBana.value = precio;

    const inputComp = card.querySelector('.input-precio-comp');
    if (inputComp) {
        calcularDiferenciaFila(inputComp);
        if (selectEl.value !== '') {
            inputComp.focus();
        }
    }
}

function calcularDiferenciaFila(inputComp) {
    const card = inputComp.closest('.fila-producto');
    const precioBana = parseFloat(card.querySelector('.input-precio-bana').value || 0);
    const precioComp = parseFloat(inputComp.value || 0);
    const badge = card.querySelector('.badge-diferencia');
    if (!badge) return;

    if (!precioComp || precioComp <= 0 || !precioBana || precioBana <= 0) {
        badge.className = 'badge bg-secondary badge-diferencia';
        badge.textContent = 'Pendiente de precio';
        badge.title = 'Ingrese precio para calcular';
        return;
    }

    const diff = precioComp - precioBana;
    if (diff > 0) {
        // La competencia cuesta más -> En Banasupro es MÁS BARATO -> VERDE
        badge.className = 'badge bg-success badge-diferencia text-white';
        badge.innerHTML = `<i class="bi bi-check-circle"></i> Banasupro más barato (Ahorro: L. ${diff.toFixed(2)})`;
    } else if (diff < 0) {
        // La competencia cuesta menos -> En Banasupro es MÁS CARO -> ROJO
        const difBanaCara = Math.abs(diff).toFixed(2);
        badge.className = 'badge bg-danger badge-diferencia text-white';
        badge.innerHTML = `<i class="bi bi-exclamation-triangle"></i> Banasupro más caro (+L. ${difBanaCara})`;
    } else {
        badge.className = 'badge bg-info text-dark badge-diferencia';
        badge.textContent = 'Mismo Precio (L. 0.00)';
    }
}