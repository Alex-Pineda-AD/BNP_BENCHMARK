// js/benchmark_js/benchmark_competidores.js
document.addEventListener('DOMContentLoaded', () => {
    cargarCompetidores();

    document.getElementById('formCompetidor').addEventListener('submit', function(e) {
        e.preventDefault();
        guardarCompetidor();
    });
});

function cargarCompetidores() {
    fetch('../../controllers/benchmark_controllers/benchmark_competidores_controller.php?action=listar')
        .then(res => res.json())
        .then(res => {
            if (res.status === 'success') {
                const tbody = document.getElementById('tbodyCompetidores');
                tbody.innerHTML = '';
                
                res.data.forEach(item => {
                    const badge = item.estado === 'activo' 
                        ? '<span class="badge bg-success">Activo</span>' 
                        : '<span class="badge bg-danger">Inactivo</span>';

                    tbody.innerHTML += `
                        <tr>
                            <td>${item.id}</td>
                            <td>${item.nombre}</td>
                            <td>${item.ubicacion_referencia}</td>
                            <td>${badge}</td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary" onclick='editarCompetidor(${JSON.stringify(item)})'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
            }
        });
}

function abrirModalCompetidor() {
    document.getElementById('formCompetidor').reset();
    document.getElementById('competidor_id').value = '';
    document.getElementById('modalCompetidorTitulo').innerText = 'Nuevo Competidor';
    const modal = new bootstrap.Modal(document.getElementById('modalCompetidor'));
    modal.show();
}

function editarCompetidor(item) {
    document.getElementById('competidor_id').value = item.id;
    document.getElementById('nombre').value = item.nombre;
    document.getElementById('ubicacion_referencia').value = item.ubicacion_referencia === 'N/A' ? '' : item.ubicacion_referencia;
    document.getElementById('estado').value = item.estado;
    document.getElementById('modalCompetidorTitulo').innerText = 'Editar Competidor';
    
    const modal = new bootstrap.Modal(document.getElementById('modalCompetidor'));
    modal.show();
}

function guardarCompetidor() {
    const formData = new FormData(document.getElementById('formCompetidor'));
    formData.append('action', 'guardar');

    fetch('../../controllers/benchmark_controllers/benchmark_competidores_controller.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'success') {
            const modalEl = document.getElementById('modalCompetidor');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            cargarCompetidores();
        } else {
            alert(res.message);
        }
    });
}