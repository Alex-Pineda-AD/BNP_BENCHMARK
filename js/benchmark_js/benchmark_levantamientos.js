let productosGlobal = [];

const COLOR_PRIMARIO = '#435ebe'; // color primario de Mazer

function obtenerEndpointGuardarFila() {
    // Ruta relativa a la página (modules/benchmark/): funciona en cualquier carpeta o servidor
    return '../../controllers/benchmark_controllers/benchmark_guardar_fila_controller.php';
}

function formatoLempiras(valor) {
    return 'L. ' + Number(valor).toFixed(2);
}

function escaparHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto;
    return div.innerHTML;
}

// Base de comparación de la fila: 'unidad' o 'paquete' (solo si el artículo tiene paquete)
function baseDeFila(row) {
    const sel = row.querySelector('.sel-base-comparacion');
    return sel && sel.value === 'paquete' && row.dataset.precioPaquete ? 'paquete' : 'unidad';
}

// Precio propio según la base elegida (el servidor lo vuelve a tomar de la base de datos al guardar)
function precioPropioDeFila(row) {
    return baseDeFila(row) === 'paquete'
        ? parseFloat(row.dataset.precioPaquete)
        : parseFloat(row.dataset.precioUnidad);
}

function textoBase(base, factor) {
    return base === 'paquete' ? 'paquete ×' + factor : 'unidad';
}

// Al cambiar entre unidad y paquete se actualiza el precio propio mostrado
function alCambiarBase(event) {
    const sel = event.target.closest('.sel-base-comparacion');
    if (!sel) { return; }
    const row = sel.closest('.fila-producto');
    const base = baseDeFila(row);
    const valor = row.querySelector('.precio-propio-valor');
    const etiqueta = row.querySelector('.etiqueta-base-propia');
    if (valor) { valor.textContent = formatoLempiras(precioPropioDeFila(row)); }
    if (etiqueta) { etiqueta.textContent = base === 'paquete' ? 'por paquete ×' + row.dataset.factor : 'por unidad'; }
    // El precio escrito era de la otra base: se limpia y se marca la fila como pendiente
    const input = row.querySelector('.input-precio-comp');
    if (input) { input.value = ''; input.focus(); }
    if (row.dataset.guardado === '1') {
        row.dataset.guardado = '0';
        row.style.backgroundColor = '';
        actualizarContadores();
    }
}
document.addEventListener('change', alCambiarBase);

function restaurarBoton(button) {
    button.disabled = false;
    button.innerHTML = '<i class="fas fa-save"></i>';
}

function avisar(icono, titulo, texto) {
    return Swal.fire({
        icon: icono,
        title: titulo,
        text: texto,
        confirmButtonColor: COLOR_PRIMARIO,
        confirmButtonText: 'Entendido'
    });
}

// Paso 1: validar y pedir confirmación mostrando ambos precios
async function guardarFilaIndividual(button, idProducto, precioBanasupro) {
    const row = button.closest('.fila-producto') || button.closest('tr');
    if (!row) {
        avisar('error', 'Error', 'No se encontró la fila del producto. Recarga la página con Ctrl+F5.');
        return;
    }
    const baseComparacion = baseDeFila(row);
    if (baseComparacion === 'paquete') {
        precioBanasupro = precioPropioDeFila(row); // paquete: PRECIO LISTA tal cual Softland
    }

    const selectCompetidor = document.getElementById('global_id_competidor');
    const idCompetidor = selectCompetidor.value;
    const observacionesVisita = document.getElementById('global_observaciones').value;

    const inputPrecio = row.querySelector('.input-precio-comp');
    const precioCompetencia = parseFloat(inputPrecio.value);
    const inputObs = row.querySelector('.input-obs-comp');
    const observacionesItem = inputObs ? inputObs.value : '';

    if (!idCompetidor) {
        await avisar('warning', 'Falta el competidor', 'Seleccione primero un competidor en la parte superior.');
        selectCompetidor.focus();
        return;
    }
    if (isNaN(precioCompetencia) || precioCompetencia <= 0) {
        await avisar('warning', 'Precio no válido', 'Ingrese un precio válido para la competencia en este producto.');
        inputPrecio.focus();
        return;
    }

    const nombreProducto = (row.querySelector('.nombre-producto, strong')?.textContent || 'este producto').trim();
    const nombreCompetidor = selectCompetidor.options[selectCompetidor.selectedIndex].text;

    const diferencia = precioCompetencia - precioBanasupro;
    const signo = diferencia > 0 ? '+' : (diferencia < 0 ? '-' : '');
    const porcentaje = precioBanasupro > 0
        ? ' (' + signo + Math.abs((diferencia / precioBanasupro) * 100).toFixed(1) + '%)'
        : '';
    // Verde: la competencia vende más caro que nosotros. Rojo: más barato.
    const claseDif = diferencia > 0 ? 'text-success' : (diferencia < 0 ? 'text-danger' : 'text-muted');

    const confirmacion = await Swal.fire({
        icon: 'question',
        title: '¿Deseas comparar estos precios?',
        html:
            '<div class="text-start">' +
            '<p class="mb-2"><strong>' + escaparHtml(nombreProducto) + '</strong><br>' +
            '<small class="text-muted">Competidor: ' + escaparHtml(nombreCompetidor) + '</small></p>' +
            '<table class="table table-sm mb-0">' +
            '<tr><td>Comparación</td><td class="text-end">' + escaparHtml(textoBase(baseComparacion, row.dataset.factor)) + '</td></tr>' +
            '<tr><td>Precio Banasupro</td><td class="text-end fw-bold">' + formatoLempiras(precioBanasupro) + '</td></tr>' +
            '<tr><td>Precio Competencia</td><td class="text-end fw-bold">' + formatoLempiras(precioCompetencia) + '</td></tr>' +
            '<tr><td>Diferencia</td><td class="text-end fw-bold ' + claseDif + '">' +
            signo + formatoLempiras(Math.abs(diferencia)) + porcentaje + '</td></tr>' +
            '</table>' +
            '</div>',
        showCancelButton: true,
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: COLOR_PRIMARIO,
        reverseButtons: true
    });

    if (!confirmacion.isConfirmed) {
        return; // Cancelado: no se guarda nada
    }

    enviarGuardado(button, row, {
        id_competidor: idCompetidor,
        observaciones_visita: observacionesVisita,
        id_producto: idProducto,
        precio_competencia: precioCompetencia,
        observaciones_item: observacionesItem,
        base_comparacion: baseComparacion,
        actualizar: false
    }, nombreProducto, nombreCompetidor);
}

// Paso 2: enviar al servidor; si ya existe un precio, pedir confirmación para actualizar
function enviarGuardado(button, row, payload, nombreProducto, nombreCompetidor) {
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch(obtenerEndpointGuardarFila(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BENCHMARK_CSRF },
        body: JSON.stringify(payload)
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en el servidor: ' + response.status);
            }
            return response.text();
        })
        .then(async texto => {
            let data;
            try {
                data = JSON.parse(texto);
            } catch (e) {
                console.error('Respuesta no válida del servidor:', texto);
                throw new Error('El servidor no devolvió JSON válido.');
            }

            // Ya existe un precio para este producto y competidor en la visita de hoy
            if (!data.success && data.existe) {
                const actualizar = await Swal.fire({
                    icon: 'warning',
                    title: 'Ya existe un precio',
                    html:
                        '<div class="text-start">' +
                        '<p class="mb-2">Ya existe un precio para este artículo con este competidor.</p>' +
                        '<p class="mb-2"><strong>' + escaparHtml(nombreProducto) + '</strong><br>' +
                        '<small class="text-muted">Competidor: ' + escaparHtml(nombreCompetidor) + '</small></p>' +
                        '<table class="table table-sm mb-2">' +
                        '<tr><td>Precio registrado' + (data.base_actual ? ' (' + escaparHtml(data.base_actual) + ')' : '') + '</td><td class="text-end fw-bold">' + formatoLempiras(data.precio_actual) + '</td></tr>' +
                        '<tr><td>Precio nuevo (' + escaparHtml(payload.base_comparacion) + ')</td><td class="text-end fw-bold text-primary">' + formatoLempiras(payload.precio_competencia) + '</td></tr>' +
                        '</table>' +
                        '<p class="mb-0">¿Deseas actualizar el precio?</p>' +
                        '</div>',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, actualizar',
                    cancelButtonText: 'No, regresar',
                    confirmButtonColor: COLOR_PRIMARIO,
                    reverseButtons: true
                });

                if (actualizar.isConfirmed) {
                    payload.actualizar = true;
                    enviarGuardado(button, row, payload, nombreProducto, nombreCompetidor);
                } else {
                    restaurarBoton(button); // No actualiza, regresa
                }
                return;
            }

            if (data.success) {
                row.style.backgroundColor = '#d1e7dd'; // verde suave de éxito
                row.dataset.guardado = '1';
                actualizarContadores();
                button.classList.remove('btn-outline-success');
                button.classList.add('btn-success');
                button.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => { button.disabled = false; }, 2000);

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: data.message || 'Precio guardado correctamente',
                    showConfirmButton: false,
                    timer: 2500,
                    timerProgressBar: true
                });
            } else {
                await avisar('error', 'Error al guardar', data.message || 'Desconocido');
                restaurarBoton(button);
            }
        })
        .catch(error => {
            console.error('Error detallado:', error);
            avisar('error', 'Error técnico', 'No se pudo guardar. Revisa la consola (F12).');
            restaurarBoton(button);
        });
}

// Contadores "capturados X/Y" por categoría y total de la visita
function actualizarContadores() {
    let hechos = 0;
    let total = 0;
    document.querySelectorAll('.panel-categoria[data-categoria]').forEach(panel => {
        const filas = panel.querySelectorAll('.fila-producto');
        const guardadas = panel.querySelectorAll('.fila-producto[data-guardado="1"]').length;
        hechos += guardadas;
        total += filas.length;
        const contador = document.querySelector('.contador-categoria[data-categoria="' + panel.dataset.categoria + '"]');
        if (contador) {
            contador.textContent = guardadas + '/' + filas.length;
            const completa = filas.length > 0 && guardadas === filas.length;
            contador.classList.toggle('bg-success', completa);
            contador.classList.toggle('text-white', completa);
            contador.classList.toggle('bg-light', !completa);
            contador.classList.toggle('text-dark', !completa);
        }
    });
    document.querySelectorAll('.acordeon-sub').forEach(sub => {
        const filas = sub.querySelectorAll('.fila-producto');
        const guardadas = sub.querySelectorAll('.fila-producto[data-guardado="1"]').length;
        const c = sub.querySelector('.contador-sub');
        if (c) {
            c.textContent = guardadas + '/' + filas.length;
            const completa = filas.length > 0 && guardadas === filas.length;
            c.classList.toggle('bg-success', completa);
            c.classList.toggle('text-white', completa);
            c.classList.toggle('bg-light', !completa);
            c.classList.toggle('text-dark', !completa);
        }
    });
    const badge = document.getElementById('badgeTotalCapturados');
    if (badge) { badge.textContent = hechos + ' de ' + total; }
}

document.addEventListener('DOMContentLoaded', actualizarContadores);

// ---------- Precios al día (en segundo plano) ----------
// Si los precios guardados tienen más de una hora, se pide la actualización a Softland sin detener la pantalla.
function actualizarPreciosEnSegundoPlano() {
    if (typeof BENCHMARK_SYNC_VENCIDA === 'undefined' || !BENCHMARK_SYNC_VENCIDA) {
        return;
    }
    fetch('../../controllers/benchmark_controllers/benchmark_sincronizar_ajax_controller.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': BENCHMARK_CSRF }
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.actualizado) {
                return;
            }
            const etiqueta = document.getElementById('bmUltimaSync');
            if (etiqueta) {
                etiqueta.textContent = 'Última: ' + data.hora;
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: 'info',
                    title: 'Precios actualizados desde Softland',
                    showConfirmButton: true,
                    confirmButtonText: 'Ver cambios',
                    showCloseButton: true,
                    timer: 20000,
                    timerProgressBar: true
                }).then(res => { if (res.isConfirmed) { window.location.reload(); } });
            }
        })
        .catch(err => console.warn('No se pudo actualizar en segundo plano:', err));
}
document.addEventListener('DOMContentLoaded', actualizarPreciosEnSegundoPlano);

// ---------- Vistas: "Listado por Softland" / "Listado por manejo en ventas" ----------
// Las filas se renderizan una sola vez y se MUEVEN entre las dos estructuras (no se duplican),
// así lo capturado, el estado "guardado" y los contadores siguen siendo los mismos en ambas vistas.
const CLAVE_VISTA_LEVANTAMIENTO = 'bm_lev_vista';

function aplicarVista(vista) {
    const porFamilia = vista === 'familia';
    const filas = Array.from(document.querySelectorAll('.fila-producto[data-destino-softland]'));
    filas.sort(porFamilia
        ? (a, b) => a.querySelector('.nombre-producto').textContent.localeCompare(b.querySelector('.nombre-producto').textContent, 'es')
        : (a, b) => Number(a.dataset.orden) - Number(b.dataset.orden));
    filas.forEach(fila => {
        const destino = porFamilia
            ? document.getElementById(fila.dataset.destinoFamilia)
            : document.querySelector('#' + fila.dataset.destinoSoftland + ' .bm-acordeon-cuerpo');
        if (destino) { destino.appendChild(fila); }
    });
    // Franjas alternas de cada lista tras el movimiento
    document.querySelectorAll('#matrizProductos .bm-acordeon-cuerpo').forEach(lista => {
        Array.from(lista.children).filter(f => f.classList.contains('fila-producto'))
            .forEach((f, j) => f.classList.toggle('bg-light', j % 2 === 1));
    });

    const matriz = document.getElementById('matrizProductos');
    if (matriz) { matriz.classList.toggle('vista-familia', porFamilia); }
    const vSoftland = document.getElementById('vistaSoftland');
    const vFamilia = document.getElementById('vistaFamilia');
    if (vSoftland) { vSoftland.classList.toggle('d-none', porFamilia); }
    if (vFamilia) { vFamilia.classList.toggle('d-none', !porFamilia); }
    document.querySelectorAll('.bm-vistas [data-vista]').forEach(b => {
        const activo = b.dataset.vista === (porFamilia ? 'familia' : 'softland');
        b.classList.toggle('active', activo);
        b.setAttribute('aria-pressed', activo ? 'true' : 'false');
    });
    try { localStorage.setItem(CLAVE_VISTA_LEVANTAMIENTO, porFamilia ? 'familia' : 'softland'); } catch (e) { /* sin almacenamiento: no pasa nada */ }
    actualizarContadores();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bm-vistas [data-vista]').forEach(b => b.addEventListener('click', () => aplicarVista(b.dataset.vista)));
    let guardada = null;
    try { guardada = localStorage.getItem(CLAVE_VISTA_LEVANTAMIENTO); } catch (e) { guardada = null; }
    if (guardada === 'familia') { aplicarVista('familia'); }
});
