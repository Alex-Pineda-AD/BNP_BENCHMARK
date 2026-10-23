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
});

function abrirModalLevantamiento() {
    const form = document.getElementById('formLevantamiento');
    if (form) form.reset();

    // Limpiar filas anteriores y agregar una línea por defecto al abrir
    const contenedor = document.getElementById('contenedorFilas');
    if (contenedor) {
        contenedor.innerHTML = '';
        agregarFilaProducto();
    }

    const modalEl = document.getElementById('modalLevantamiento');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function agregarFilaProducto() {
    const contenedor = document.getElementById('contenedorFilas');
    if (!contenedor) return;

    let opcionesHtml = '<option value="">-- Seleccionar Producto --</option>';
    if (productosGlobal && productosGlobal.length > 0) {
        productosGlobal.forEach(p => {
            let idp = p.id || p.ID;
            let nombrep = p.nombre_producto || p.NOMBRE_PRODUCTO;
            let preciop = p.precio_referencia || p.PRECIO_REFERENCIA;
            opcionesHtml += `<option value="${idp}">[Ref: L. ${preciop}] ${nombrep}</option>`;
        });
    }

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="id_producto[]" class="form-select form-select-sm" required>
                ${opcionesHtml}
            </select>
        </td>
        <td>
            <input type="text" name="nombre_competencia[]" class="form-control form-control-sm" placeholder="Marca / Nombre local">
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">L.</span>
                <input type="number" step="0.01" min="0" name="precio_competencia[]" class="form-control form-control-sm" placeholder="0.00" required>
            </div>
        </td>
        <td>
            <input type="text" name="observaciones_item[]" class="form-control form-control-sm" placeholder="Ej. Oferta">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    contenedor.appendChild(tr);
}