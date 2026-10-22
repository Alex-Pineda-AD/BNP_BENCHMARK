// La consulta y el foreach de las filas están en controllers/filtrar_movimientos_estado.php.
const formularioFiltros = document.getElementById('filtros-movimientos');
const anterior = document.getElementById('pagina-anterior');
const siguiente = document.getElementById('pagina-siguiente');
const mensaje = document.getElementById('mensaje-movimientos');
let paginaActual = 1;
let totalPaginas = 1;
let cargando = false;
let filtrosAplicados = '';
let cargaActual = null;
let consultaActual = null;
let esperaBusqueda = null;
const buscadorMovimientos = document.getElementById('buscar-movimientos');
buscadorMovimientos.addEventListener('input', function () {
    clearTimeout(esperaBusqueda);
    // Una pausa breve evita una consulta por cada tecla.
    esperaBusqueda = setTimeout(function () { filtrarMovimientos(); }, 300);
});
buscadorMovimientos.addEventListener('keydown', function (evento) {
    if (evento.key === 'Enter') {
        evento.preventDefault();
        clearTimeout(esperaBusqueda);
        filtrarMovimientos();
    }
});

function aplicarColumnas() {
    const opciones = document.querySelectorAll('#columnas-movimientos input');
    const visibles = Array.from(opciones).filter(opcion => opcion.checked).length;
    document.querySelectorAll('#tabla-movimientos tr').forEach(fila => {
        if (fila.cells.length === 1 && fila.cells[0].hasAttribute('colspan')) {
            fila.cells[0].colSpan = visibles;
        } else {
            opciones.forEach((opcion, indice) => {
                if (fila.cells[indice]) fila.cells[indice].hidden = !opcion.checked;
            });
        }
    });
    opciones.forEach(opcion => opcion.disabled = visibles === 1 && opcion.checked);
}
document.getElementById('columnas-movimientos').addEventListener('change', aplicarColumnas);

function limpiarCargaActual() {
    clearTimeout(esperaBusqueda);
    buscadorMovimientos.value = '';
    document.getElementById('panel-resumen-mensual').hidden = true;
    document.getElementById('resumen-mensual').innerHTML = '';
    if (consultaActual) consultaActual.abort();
    consultaActual = null;
    cargando = false;
    cargaActual = null;
    paginaActual = totalPaginas = 1;
    filtrosAplicados = '';
    anterior.disabled = siguiente.disabled = true;
    formularioFiltros.querySelectorAll('button').forEach(boton => boton.disabled = false);
    document.getElementById('movimientos-estado').innerHTML = '<tr><td colspan="8">Subí un archivo o presioná Buscar para consultar el historial.</td></tr>';
    document.getElementById('numero-pagina').textContent = 'Página 1';
    mensaje.textContent = 'Podés buscar movimientos anteriores por fecha y banco.';
    aplicarColumnas();
}

async function filtrarMovimientos(pagina = null) {
    if (consultaActual) consultaActual.abort();
    const consulta = new AbortController();
    consultaActual = consulta;
    cargando = true;
    // Bloquear los botones mientras llega la respuesta evita consultas simultáneas.
    anterior.disabled = true;
    siguiente.disabled = true;
    formularioFiltros.querySelectorAll('button').forEach(boton => boton.disabled = true);
    mensaje.textContent = 'Cargando movimientos…';

    // Buscar empieza en la página 1. Anterior/Siguiente conservan el filtro aplicado.
    const filtros = pagina === null
        ? new URLSearchParams(new FormData(formularioFiltros)).toString()
        : filtrosAplicados;
    pagina = pagina ?? 1;
    try {
        const respuesta = await fetch('../../controllers/filtrar_movimientos_estado.php?' + filtros + '&pagina=' + pagina + '&carga_id=' + (cargaActual || 0), { signal: consulta.signal });
        const datos = await respuesta.json();
        if (consultaActual !== consulta) return;
        if (!respuesta.ok) throw new Error(datos.mensaje);

        // PHP ya escapó los datos y preparó las filas HTML con un foreach.
        document.getElementById('movimientos-estado').innerHTML = datos.html;
        document.getElementById('scroll-movimientos').scrollTop = 0;
        document.getElementById('resumen-mensual').innerHTML = datos.resumen;
        document.getElementById('panel-resumen-mensual').hidden = false;
        aplicarColumnas();
        paginaActual = datos.pagina;
        totalPaginas = datos.paginas;
        filtrosAplicados = filtros;
        document.getElementById('numero-pagina').textContent = `Página ${paginaActual} de ${totalPaginas}`;
        mensaje.textContent = `${cargaActual ? 'Carga actual' : 'Historial'}: ${datos.total} registros encontrados. 50 por página.`;
    } catch (error) {
        if (consultaActual !== consulta || error.name === 'AbortError') return;
        mensaje.textContent = 'No se pudo actualizar la tabla: ' + error.message;
    } finally {
        if (consultaActual !== consulta) return;
        cargando = false;
        formularioFiltros.querySelectorAll('button').forEach(boton => boton.disabled = false);
        anterior.disabled = paginaActual <= 1;
        siguiente.disabled = paginaActual >= totalPaginas;
    }
}

formularioFiltros.addEventListener('submit', function (evento) {
    evento.preventDefault();
    clearTimeout(esperaBusqueda);
    if (cargando) return;
    cargaActual = null;
    filtrarMovimientos();
});
document.getElementById('limpiar-filtros').addEventListener('click', function () {
    formularioFiltros.reset();
    limpiarCargaActual();
});
anterior.addEventListener('click', function () { filtrarMovimientos(paginaActual - 1); });
siguiente.addEventListener('click', function () { filtrarMovimientos(paginaActual + 1); });

// No recuperar cargas anteriores al abrir la pantalla ni al volver con Atrás.
limpiarCargaActual();
window.addEventListener('pageshow', function (evento) {
    if (evento.persisted) limpiarCargaActual();
});
