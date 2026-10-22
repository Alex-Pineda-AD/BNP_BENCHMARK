// Cada modal conserva su propio selector de centros de venta.
// Choices modifica las opciones del select. Guardamos el catalogo original
// para recuperarlo completo cada vez que se abre la edicion.
var opcionesCentrosEditar = document.querySelector('#editUser select[multiple]').innerHTML;
var centrosAgregar = new Choices(document.querySelector('#addUser select[multiple]'), {
    removeItemButton: true, shouldSort: false, allowHTML: false
});
var centrosEditar = new Choices(document.querySelector('#editUser select[multiple]'), {
    removeItemButton: true, shouldSort: false, allowHTML: false
});

document.getElementById('editUser').addEventListener('show.bs.modal', function (evento) {
    var usuario = JSON.parse(evento.relatedTarget.getAttribute('data-usuario'));
    var centros = JSON.parse(evento.relatedTarget.getAttribute('data-centros'));
    var formulario = this.querySelector('form');
    // Primero retiramos Choices para que reset() no borre su selección interna.
    centrosEditar.destroy();
    formulario.reset();
    formulario.elements.idUsuario.value = usuario.ID;
    formulario.elements.nombreCompleto.value = usuario.NOMBRE_COMPLETO;
    formulario.elements.dni.value = usuario.DNI;
    formulario.elements.telefono.value = usuario.NUM_TELEFONO;
    formulario.elements.correo.value = usuario.CORREO === '-' ? '' : (usuario.CORREO || '');
    formulario.elements.usuario.value = usuario.USUARIO;
    formulario.elements.idRol.value = usuario.ID_ROL;
    formulario.elements.pass.value = '';
    formulario.elements.pass.type = 'password';
    var selector = formulario.querySelector('select[multiple]');
    selector.innerHTML = opcionesCentrosEditar;
    centros = centros.map(String);
    Array.from(selector.options).forEach(function (opcion) {
        opcion.selected = centros.includes(opcion.value);
    });
    // Creamos el selector con todos los centros del usuario ya seleccionados.
    centrosEditar = new Choices(selector, {
        removeItemButton: true, shouldSort: false, allowHTML: false
    });
});

document.getElementById('addUser').addEventListener('hidden.bs.modal', function () {
    this.querySelector('form').reset();
    this.querySelector('[name="pass"]').type = 'password';
    centrosAgregar.removeActiveItems();
});

function mostrarContrasena(boton) {
    var campo = boton.parentElement.querySelector('input');
    campo.type = campo.type === 'password' ? 'text' : 'password';
}
