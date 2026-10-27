let productosGlobal = [];

const COLOR_PRIMARIO = '#435ebe'; // color primario de Mazer

function obtenerEndpointGuardarFila() {
    const base = (typeof BASE_URL !== 'undefined')
        ? BASE_URL
        : window.location.origin + '/BNP-Benchmark';
    return base + '/controllers/benchmark_controllers/benchmark_guardar_fila_controller.php';
}

function formatoLempiras(valor) {
    return 'L. ' + Number(valor).toFixed(2);
}

function escaparHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto;
    return div.innerHTML;
}

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

    const selectCompetidor = document.getElementById('global_id_competidor');
    const idCompetidor = selectCompetidor.value;
    const observacionesVisita = document.getElementById('global_observaciones').value;

    const inputPrecio = row.querySelector('.input-precio-comp');
    const precioCompetencia = parseFloat(inputPrecio.value);
    const observacionesItem = row.querySelector('.input-obs-comp').value;

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
        precio_banasupro: precioBanasupro,
        precio_competencia: precioCompetencia,
        observaciones_item: observacionesItem,
        actualizar: false
    }, nombreProducto, nombreCompetidor);
}

// Paso 2: enviar al servidor; si ya existe un precio, pedir confirmación para actualizar
function enviarGuardado(button, row, payload, nombreProducto, nombreCompetidor) {
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch(obtenerEndpointGuardarFila(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
                        '<tr><td>Precio registrado</td><td class="text-end fw-bold">' + formatoLempiras(data.precio_actual) + '</td></tr>' +
                        '<tr><td>Precio nuevo</td><td class="text-end fw-bold text-primary">' + formatoLempiras(payload.precio_competencia) + '</td></tr>' +
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