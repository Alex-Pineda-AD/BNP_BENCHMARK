// Pantalla: modules/conciliacion/cargar_estado_cuentas_txt.php
// Controlador que guarda: controllers/estado_cuenta_txt.php
const formularioEstado = document.getElementById('form-subir-estado');
const botonSubirEstado = formularioEstado.querySelector('button[type="submit"]');

formularioEstado.addEventListener('submit', async function (evento) {
    // 1. Evitar que el navegador recargue la página o muestre el JSON.
    evento.preventDefault();
    if (botonSubirEstado.disabled) return;

    botonSubirEstado.disabled = true;
    botonSubirEstado.textContent = 'Guardando…';
    limpiarCargaActual();

    try {
        // 2. Enviar banco, archivo y token de sesión al controlador existente.
        // FormData prepara el archivo; no hay que definir Content-Type manualmente.
        const respuesta = await fetch(formularioEstado.action, {
            method: 'POST',
            body: new FormData(formularioEstado),
            headers: { Accept: 'application/json' }
        });
        const datos = await respuesta.json();

        // El controlador devuelve un mensaje si el archivo es inválido o duplicado.
        if (!respuesta.ok) {
            throw new Error(datos.mensaje || 'No se pudo guardar el estado de cuenta.');
        }

        limpiarCargaActual();
        cargaActual = datos.id;
        formularioFiltros.reset();
        await filtrarMovimientos();
        // 3. Mostrar éxito únicamente cuando el servidor confirmó el guardado.
        let mensaje = datos.mensaje + '\nMovimientos guardados: ' + datos.transacciones;
        if (datos.advertencias && datos.advertencias.length > 0) {
            mensaje += '\n\nObservaciones:\n' + datos.advertencias.join('\n');
        }
        await Swal.fire({
            icon: 'success',
            title: 'Estado de cuenta guardado',
            text: mensaje,
            confirmButtonText: 'Aceptar'
        });

        // 4. Limpiar solo el archivo y actualizar la tabla con los filtros actuales.
        formularioEstado.querySelector('[name="archivo"]').value = '';
    } catch (error) {
        // Conservar banco y archivo para que el usuario pueda corregir o reintentar.
        await Swal.fire({
            icon: 'error',
            title: 'No se pudo completar la carga',
            text: error.message,
            confirmButtonText: 'Aceptar'
        });
    } finally {
        // Se ejecuta tanto si el guardado fue exitoso como si ocurrió un error.
        botonSubirEstado.disabled = false;
        botonSubirEstado.textContent = 'Subir y guardar';
    }
});
