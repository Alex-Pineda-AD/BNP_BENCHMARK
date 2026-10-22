var fotoTemporalDeposito = null;
var fotoGuardadaDeposito = '';

function mostrarFotoDeposito(ruta) {
    var foto = document.getElementById('fotoDepositoActual');
    foto.hidden = !ruta;
    document.getElementById('sinFotoDeposito').hidden = !!ruta;
    if (ruta) foto.src = ruta;
    else foto.removeAttribute('src');
}

function liberarFotoDeposito() {
    if (fotoTemporalDeposito) URL.revokeObjectURL(fotoTemporalDeposito);
    fotoTemporalDeposito = null;
}

document.getElementById('editarDeposito').addEventListener('show.bs.modal', function (evento) {
    var deposito = JSON.parse(evento.relatedTarget.getAttribute('data-deposito'));
    var formulario = this.querySelector('form');
    formulario.reset();
    liberarFotoDeposito();
    formulario.elements.foto.setCustomValidity('');
    document.getElementById('estadoFotoDeposito').textContent = 'Si no seleccionás otra foto, se conserva la actual. JPG, PNG o WebP, hasta 8 MB.';
    formulario.elements.idDeposito.value = deposito.ID;
    ['ID_BANCO','ID_CENTRO_VENTA','NUMERO_CUENTA','REMITENTE','BENEFICIARIO','NUMERO_REFERENCIA','NUMERO_AUTORIZACION','MONTO','MONEDA','COMENTARIO'].forEach(function (campo) {
        formulario.elements[campo].value = deposito[campo] || '';
    });
    document.getElementById('codigoDeposito').textContent = deposito.CODIGO || '#' + deposito.ID;
    document.getElementById('solicitudCorreccionDeposito').hidden = !deposito.FECHA_SOLICITUD_CORRECCION;
    document.getElementById('motivoCorreccionDeposito').textContent = deposito.MOTIVO_CORRECCION || '';
    document.getElementById('solicitanteCorreccionDeposito').textContent = deposito.FECHA_SOLICITUD_CORRECCION
        ? 'Solicitado por: ' + (deposito.SOLICITANTE_CORRECCION || 'Usuario no disponible') + ' — ' + deposito.FECHA_SOLICITUD_CORRECCION : '';
    fotoGuardadaDeposito = '';
    if (/^files\/depositos\/[a-zA-Z0-9_.-]+$/.test(deposito.FOTO_URL || '')) {
        fotoGuardadaDeposito = '../../' + deposito.FOTO_URL;
    }
    mostrarFotoDeposito(fotoGuardadaDeposito);
});

// Vista previa local: no modifica los datos del formulario ni ejecuta OCR.
document.getElementById('nuevaFotoDeposito').addEventListener('change', function () {
    liberarFotoDeposito();
    this.setCustomValidity('');
    this.form.elements.CONFIRMADO.checked = false;
    var archivo = this.files[0];
    var mensaje = document.getElementById('estadoFotoDeposito');
    if (!archivo) {
        mostrarFotoDeposito(fotoGuardadaDeposito);
        mensaje.textContent = 'Se conservará la foto actual.';
        return;
    }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type) || archivo.size > 8 * 1024 * 1024) {
        this.setCustomValidity('Seleccioná una imagen JPG, PNG o WebP de hasta 8 MB.');
        mensaje.textContent = this.validationMessage;
        mostrarFotoDeposito(fotoGuardadaDeposito);
        return;
    }
    fotoTemporalDeposito = URL.createObjectURL(archivo);
    mostrarFotoDeposito(fotoTemporalDeposito);
    mensaje.textContent = 'Nueva foto: ' + archivo.name + '. Revisá los datos a la par antes de guardar.';
});

document.getElementById('editarDeposito').addEventListener('hidden.bs.modal', function () {
    liberarFotoDeposito();
    mostrarFotoDeposito('');
});
