// Actualiza solo las alertas: no recarga la tabla ni interrumpe los modales.
var panelAlertas = document.getElementById('panelAlertasSeguimiento');
if (panelAlertas && panelAlertas.querySelector('.card')) {
    var actualizandoAlertas = false;
    var intervaloAlertas = setInterval(async function () {
        if (document.hidden || actualizandoAlertas) return;
        actualizandoAlertas = true;
        try {
            var respuesta = await fetch('../../controllers/consultar_alertas.php?pantalla=' + encodeURIComponent(panelAlertas.dataset.pantalla), {cache: 'no-store'});
            if (respuesta.status === 401 || respuesta.status === 403) {
                clearInterval(intervaloAlertas);
                panelAlertas.textContent = 'Recargá la pantalla para verificar tu sesión y permisos.';
                return;
            }
            if (!respuesta.ok) throw new Error('No se pudo actualizar');
            panelAlertas.innerHTML = await respuesta.text();
        } catch (error) {
            var aviso = panelAlertas.querySelector('[data-aviso-actualizacion]');
            if (!aviso) {
                aviso = document.createElement('p');
                aviso.dataset.avisoActualizacion = '1';
                aviso.className = 'text-warning';
                panelAlertas.appendChild(aviso);
            }
            aviso.textContent = 'No se pudieron actualizar las alertas. Los resultados anteriores pueden haber cambiado.';
        } finally {
            actualizandoAlertas = false;
        }
    }, 60000);
}
