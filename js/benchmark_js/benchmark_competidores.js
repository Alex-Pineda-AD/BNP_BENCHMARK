document.addEventListener('DOMContentLoaded', function () {
    // Si tienes elementos dinámicos o inicializaciones de la tabla, van aquí
});

function abrirModalCompetidor() {
    const form = document.getElementById('formCompetidor');
    if (form) form.reset();

    const inputId = document.getElementById('competidor_id');
    if (inputId) inputId.value = '';

    // Cambiar título y color de la franja a Azul (Primary) para Nuevo
    const headerModal = document.querySelector('#modalCompetidor .modal-header');
    if (headerModal) {
        headerModal.classList.remove('bg-warning');
        headerModal.classList.add('bg-primary');
    }

    const titulo = document.getElementById('modalCompetidorTitulo');
    if (titulo) {
        titulo.innerHTML = '<i class="fas fa-building text-white"></i> &nbsp; <span class="text-white">Nuevo Competidor</span>';
    }

    const modalEl = document.getElementById('modalCompetidor');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function editarCompetidor(item) {
    const inputId = document.getElementById('competidor_id');
    if (inputId) inputId.value = item.id ?? item.ID;

    const inputNombre = document.getElementById('nombre');
    if (inputNombre) inputNombre.value = item.nombre ?? item.NOMBRE;

    const inputUbicacion = document.getElementById('ubicacion_referencia');
    if (inputUbicacion) {
        const ubicacion = item.ubicacion_referencia ?? item.UBICACION_REFERENCIA;
        inputUbicacion.value = (ubicacion === 'N/A') ? '' : ubicacion;
    }

    const inputEstado = document.getElementById('estado');
    if (inputEstado) {
        const estadoVal = item.estado ?? item.ESTADO;
        inputEstado.value = (estadoVal == 1 || estadoVal === 'Activo' || estadoVal === 'activo') ? '1' : '0';
    }

    // Cambiar color de la franja a Amarillo (Warning) y texto oscuro al Editar
    const headerModal = document.querySelector('#modalCompetidor .modal-header');
    if (headerModal) {
        headerModal.classList.remove('bg-primary');
        headerModal.classList.add('bg-warning');
    }

    const titulo = document.getElementById('modalCompetidorTitulo');
    if (titulo) {
        titulo.innerHTML = '<i class="fas fa-edit text-dark"></i> &nbsp; <span class="text-dark">Editar Competidor</span>';
    }

    const modalEl = document.getElementById('modalCompetidor');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}