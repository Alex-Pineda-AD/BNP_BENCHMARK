// Busqueda por texto en los filtros del listado.
$('#bancoFiltro, #centroFiltro, #responsableFiltro').each(function () {
    // Anclar la lista al campo evita desplazamientos con el zoom de la plantilla.
    $(this).wrap('<div class="filtro-revision-select"></div>');
    $(this).select2({
        dropdownParent: $(this).parent(),
        width: '100%',
        language: 'es',
        minimumResultsForSearch: 0
    });
});

// Select2 dentro del modal para que la lista y el buscador reciban el foco.
$('#estadoRevision').select2({
    dropdownParent: $('#revisarDeposito'),
    placeholder: 'Seleccioná un estado',
    width: '100%',
    language: 'es'
});

$('#estadoRevision').on('change', function () {
    document.getElementById('observacionRevision').required = this.value === '7';
});

document.getElementById('revisarDeposito').addEventListener('show.bs.modal', function (evento) {
    var deposito = JSON.parse(evento.relatedTarget.getAttribute('data-deposito'));
    var formulario = this.querySelector('form');
    formulario.reset();
    formulario.elements.idDeposito.value = deposito.ID;
    formulario.elements.version.value = evento.relatedTarget.getAttribute('data-version');
    ['ID_BANCO','ID_CENTRO_VENTA','NUMERO_CUENTA','REMITENTE','BENEFICIARIO','NUMERO_REFERENCIA','NUMERO_AUTORIZACION','MONTO','MONEDA','COMENTARIO'].forEach(function (campo) {
        formulario.elements[campo].value = deposito[campo] || '';
    });
    document.getElementById('codigoDeposito').textContent = deposito.CODIGO || '#' + deposito.ID;
    document.getElementById('estadoActualDeposito').textContent = deposito.ESTADO_NOMBRE;
    $('#estadoRevision').val('').trigger('change');
    var foto = document.getElementById('fotoDepositoActual');
    foto.removeAttribute('src');
    foto.hidden = true;
    document.getElementById('sinFotoDeposito').hidden = false;
    if (/^files\/depositos\/[a-zA-Z0-9_.-]+$/.test(deposito.FOTO_URL || '')) {
        foto.src = '../../' + deposito.FOTO_URL;
        foto.hidden = false;
        document.getElementById('sinFotoDeposito').hidden = true;
    }
});

// Select2 oculta el select original; dirigir alli los errores de campo obligatorio.
document.getElementById('estadoRevision').addEventListener('invalid', function (evento) {
    evento.preventDefault();
    $('#estadoRevision').select2('open');
});
