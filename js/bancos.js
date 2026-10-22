// Cada modal conserva su propio selector de centros de venta.
// Choices modifica las opciones del select. Guardamos el catalogo original
// para recuperarlo completo cada vez que se abre la edicion.

document.getElementById('editUser').addEventListener('show.bs.modal', function (evento) {
    var banco = JSON.parse(evento.relatedTarget.getAttribute('data-banco'));
    //var centros = JSON.parse(evento.relatedTarget.getAttribute('data-centros'));
    var formulario = this.querySelector('form');
    formulario.reset();
    formulario.elements.idBanco.value = banco.ID;
    formulario.elements.descripcion.value = banco.DESCRIPCION;
    formulario.elements.nuemroCuenta.value = banco.N_CUENTA;
});



function mostrarContrasena(boton) {
    var campo = boton.parentElement.querySelector('input');
    campo.type = campo.type === 'password' ? 'text' : 'password';
}
