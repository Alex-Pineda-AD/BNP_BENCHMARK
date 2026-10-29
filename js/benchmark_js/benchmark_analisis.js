// Módulo Análisis de Precios (Benchmark): filtros -> fetch JSON -> gráficas ApexCharts.
// Requiere BENCHMARK_CSRF definido en la página.

const ENDPOINT_ANALISIS = '../../controllers/benchmark_controllers/benchmark_analisis_controller.php';

const COLOR_PRIMARIO = '#435ebe'; // color primario de Mazer
const COLOR_BARATO = '#198754';
const COLOR_CARO = '#dc3545';
const PALETA_COMPETENCIA = ['#ff7976', '#57caeb', '#5ddab4', '#ffc107', '#9b59b6', '#fd7e14', '#20c997'];

const graficas = {};
let peticionActual = 0;
// Producto que pidió el enlace de la tabla comparativa; solo se usa en la primera carga
let productoInicial = (typeof BENCHMARK_INICIAL !== 'undefined') ? BENCHMARK_INICIAL.id_producto : null;

const el = (id) => document.getElementById(id);

const lempiras = (v) => 'L. ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const porcentaje = (v, dec = 1) => (v > 0 ? '+' : '') + Number(v).toFixed(dec) + '%';
const aMs = (fecha) => new Date(fecha + 'T00:00:00').getTime();
const recortar = (t, n = 28) => (t.length > n ? t.slice(0, n - 1) + '…' : t);
const fechaCorta = (f) => f.split('-').reverse().slice(0, 2).join('/'); // 2026-10-29 -> 29/10

function fechaLocal(d) {
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
}

// ---------- Filtros ----------
function leerFiltros() {
    const p = new URLSearchParams();
    p.set('desde', el('fDesde').value);
    p.set('hasta', el('fHasta').value);
    p.set('agrupar', el('fAgrupar').value);
    if (el('fCompetidor').value) p.set('id_competidor', el('fCompetidor').value);
    const producto = el('fProducto').value || (productoInicial ? String(productoInicial) : '');
    if (producto) p.set('id_producto', producto);
    return p;
}

function aplicarAtajo(dias) {
    const hoy = new Date();
    const desde = new Date(hoy);
    if (dias === 'mes') desde.setDate(1);
    else desde.setDate(hoy.getDate() - (Number(dias) - 1));
    el('fDesde').value = fechaLocal(desde);
    el('fHasta').value = fechaLocal(hoy);
    el('fProducto').value = '';
    cargar();
}

function limpiarFiltros() {
    const hoy = new Date();
    const desde = new Date(hoy);
    desde.setDate(hoy.getDate() - 29);
    el('fDesde').value = fechaLocal(desde);
    el('fHasta').value = fechaLocal(hoy);
    el('fCompetidor').value = '';
    el('fAgrupar').value = 'semana';
    el('fProducto').value = '';
    cargar();
}

// ---------- Carga ----------
function mostrarError(mensaje) {
    const caja = el('alertaAnalisis');
    caja.textContent = mensaje;
    caja.classList.remove('d-none');
}

async function cargar() {
    const numero = ++peticionActual;
    el('alertaAnalisis').classList.add('d-none');
    el('cargandoAnalisis').style.display = 'flex';

    try {
        const resp = await fetch(ENDPOINT_ANALISIS + '?' + leerFiltros().toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': BENCHMARK_CSRF }
        });
        const datos = await resp.json();
        if (numero !== peticionActual) return; // llegó una respuesta más nueva
        if (!resp.ok || !datos.success) {
            mostrarError(datos.message || 'No se pudo cargar el análisis.');
            return;
        }
        pintar(datos);
        productoInicial = null;
    } catch (e) {
        if (numero === peticionActual) mostrarError('No se pudo conectar con el servidor. Revisá tu conexión e intentá de nuevo.');
    } finally {
        if (numero === peticionActual) el('cargandoAnalisis').style.display = 'none';
    }
}

function pintar(d) {
    pintarKpis(d);
    llenarProductos(d.productos, d.producto_id);
    graficarEvolucion(d.evolucion);
    graficarVariacion(d.variaciones, d.filtros, d.variacion_resumen);
    graficarCompetitividad(d.competitividad);
    graficarCobertura(d.cobertura);
}

// ---------- Indicadores ----------
function colorPorSigno(v) {
    return v > 0 ? 'text-success' : (v < 0 ? 'text-danger' : 'text-muted');
}

function pintarKpis(d) {
    const k = d.kpis;
    el('kpiMuestras').textContent = k.muestras.toLocaleString('en-US');
    el('kpiMuestrasSub').textContent = k.productos + ' productos · ' + k.visitas + ' visitas';

    el('kpiBarato').textContent = k.pct_barato === null ? '–' : k.pct_barato + '%';
    el('kpiBaratoSub').textContent = k.muestras ? k.barato + ' de ' + k.muestras + ' comparaciones' : 'Sin datos';

    const brecha = el('kpiBrecha');
    brecha.className = 'fw-bold mb-0 ' + (k.brecha === null ? '' : colorPorSigno(k.brecha));
    brecha.textContent = k.brecha === null ? '–' : porcentaje(k.brecha, 2);
    el('kpiBrechaSub').textContent = k.brecha === null ? 'Sin datos'
        : (k.brecha >= 0 ? 'La competencia está más cara' : 'La competencia está más barata');

    const vari = el('kpiVariacion');
    vari.className = 'fw-bold mb-0 ' + (k.variacion === null ? '' : colorPorSigno(k.variacion));
    vari.textContent = k.variacion === null ? '–' : porcentaje(k.variacion, 2);
    el('kpiVariacionSub').textContent = k.variacion === null ? 'Sin datos en ambos periodos'
        : 'vs. ' + fechaCorta(d.filtros.desde_anterior) + ' – ' + fechaCorta(d.filtros.hasta_anterior);
}

function llenarProductos(productos, seleccionado) {
    const sel = el('fProducto');
    sel.innerHTML = '';
    if (!productos.length) {
        const o = document.createElement('option');
        o.value = '';
        o.textContent = 'Sin productos en este filtro';
        sel.appendChild(o);
        sel.disabled = true;
        return;
    }
    sel.disabled = false;
    productos.forEach((p) => {
        const o = document.createElement('option');
        o.value = p.id;
        o.textContent = p.nombre + ' (' + p.n + ')';
        if (p.id === seleccionado) o.selected = true;
        sel.appendChild(o);
    });
}

// ---------- Utilidades de gráficas ----------
function dibujar(idGrafica, idVacio, opciones, hayDatos) {
    if (graficas[idGrafica]) {
        graficas[idGrafica].destroy();
        delete graficas[idGrafica];
    }
    el(idVacio).style.display = hayDatos ? 'none' : 'block';
    el(idGrafica).style.display = hayDatos ? 'block' : 'none';
    if (!hayDatos) return;
    graficas[idGrafica] = new ApexCharts(el(idGrafica), opciones);
    graficas[idGrafica].render();
}

const BASE_CHART = { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' };

// ---------- 1. Evolución del precio ----------
function graficarEvolucion(ev) {
    const series = [{ name: 'Banasupro', data: ev.banasupro.map((p) => [aMs(p[0]), p[1]]) }];
    ev.competidores.forEach((c) => series.push({ name: c.nombre, data: c.data.map((p) => [aMs(p[0]), p[1]]) }));
    const colores = [COLOR_PRIMARIO].concat(series.slice(1).map((_, i) => PALETA_COMPETENCIA[i % PALETA_COMPETENCIA.length]));

    dibujar('chartEvolucion', 'vacioEvolucion', {
        chart: Object.assign({ type: 'line', height: 340 }, BASE_CHART),
        series: series,
        colors: colores,
        stroke: { width: series.map((_, i) => (i === 0 ? 4 : 3)), curve: 'smooth' },
        markers: { size: 5, hover: { size: 7 } },
        xaxis: { type: 'datetime', labels: { datetimeUTC: false, format: 'dd MMM' } },
        yaxis: { labels: { formatter: (v) => lempiras(v) } },
        tooltip: { shared: true, x: { format: 'dd/MM/yyyy' }, y: { formatter: (v) => (v == null ? '–' : lempiras(v)) } },
        legend: { position: 'top' },
        grid: { borderColor: '#eef0f4' }
    }, ev.banasupro.length > 0);
}

// ---------- 2. ¿Qué cambió en la competencia? (variación vs periodo anterior) ----------
function graficarVariacion(lista, filtros, resumen) {
    el('subVariacion').textContent = 'Contra el periodo anterior (' + fechaCorta(filtros.desde_anterior) + ' – ' + fechaCorta(filtros.hasta_anterior) + ')';

    // La lista llega de mayor subida a mayor bajada: mostramos las 5 mayores subidas y las 5 mayores bajadas
    const mostrar = lista.filter((x) => x.pct > 0).slice(0, 5).concat(lista.filter((x) => x.pct < 0).slice(-5));

    el('insightVariacion').textContent = resumen && resumen.total
        ? resumen.suben + ' productos subieron y ' + resumen.bajan + ' bajaron (de ' + resumen.total + ' comparables). Mayor cambio: '
            + recortar(lista[0].pct >= Math.abs(lista[lista.length - 1].pct) ? lista[0].nombre : lista[lista.length - 1].nombre, 24) + '.'
        : '';

    dibujar('chartVariacion', 'vacioVariacion', {
        chart: Object.assign({ type: 'bar', height: 300 }, BASE_CHART),
        series: [{ name: 'Cambio', data: mostrar.map((x) => x.pct) }],
        plotOptions: {
            bar: {
                horizontal: true, borderRadius: 3, barHeight: '70%',
                colors: { ranges: [{ from: -1000000, to: -0.0001, color: COLOR_CARO }, { from: 0, to: 1000000, color: COLOR_BARATO }] }
            }
        },
        dataLabels: { enabled: true, formatter: (v) => porcentaje(v, 1), style: { fontSize: '10px' } },
        xaxis: { categories: mostrar.map((x) => recortar(x.nombre, 16)), labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { labels: { maxWidth: 120, style: { fontSize: '11px' } } },
        tooltip: {
            y: {
                title: { formatter: () => '' },
                formatter: (v, o) => {
                    const it = mostrar[o.dataPointIndex];
                    return it.nombre + ': ' + porcentaje(v, 2) + ' (' + lempiras(it.anterior) + ' → ' + lempiras(it.actual) + ')';
                }
            }
        },
        legend: { show: false },
        grid: { show: false }
    }, mostrar.length > 0);
}

// ---------- 3. ¿Estamos ganando en precio? ----------
function graficarCompetitividad(lista) {
    const total = lista.reduce((a, x) => a + x.total, 0);
    const promedio = total ? lista.reduce((a, x) => a + x.pct_barato * x.total, 0) / total : 0;
    const ultimo = lista.length ? lista[lista.length - 1].pct_barato : 0;
    el('insightCompetitividad').textContent = lista.length
        ? 'Promedio del periodo: ' + promedio.toFixed(0) + '% más barato. Última medición: ' + ultimo + '%.'
        : '';

    dibujar('chartCompetitividad', 'vacioCompetitividad', {
        chart: Object.assign({ type: 'area', height: 300 }, BASE_CHART),
        series: [{ name: 'Banasupro más barato', data: lista.map((x) => [aMs(x.periodo), x.pct_barato]) }],
        colors: [COLOR_BARATO],
        stroke: { width: 3, curve: 'smooth' },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 95, 100] } },
        markers: { size: 4 },
        xaxis: { type: 'datetime', labels: { datetimeUTC: false, format: 'dd MMM' } },
        yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: (v) => Math.round(v) + '%' } },
        annotations: {
            yaxis: [{
                y: 50, borderColor: '#6c757d', strokeDashArray: 4,
                label: { text: '50%: equilibrio', position: 'left', textAnchor: 'start', offsetX: 6, style: { color: '#fff', background: '#6c757d', fontSize: '10px' } }
            }]
        },
        tooltip: { x: { format: 'dd/MM/yyyy' }, y: { formatter: (v) => v + '% más barato' } },
        legend: { show: false },
        dataLabels: { enabled: false },
        grid: { borderColor: '#eef0f4' }
    }, lista.length > 0);
}

// ---------- 4. ¿Qué tan confiables son los datos? ----------
function graficarCobertura(lista) {
    if (!lista.length) {
        el('insightCobertura').textContent = '';
    } else if (lista.length === 1) {
        el('insightCobertura').textContent = lista[0].competidor + ': ' + lista[0].visitas + ' visitas y ' + lista[0].productos + ' productos.';
    } else {
        const menos = lista[lista.length - 1]; // la lista llega ordenada de más a menos visitas
        el('insightCobertura').textContent = 'Menos datos: ' + menos.competidor + ' (' + menos.visitas + ' visitas, ' + menos.productos + ' productos).';
    }

    dibujar('chartCobertura', 'vacioCobertura', {
        chart: Object.assign({ type: 'bar', height: 300 }, BASE_CHART),
        series: [
            { name: 'Visitas', data: lista.map((x) => x.visitas) },
            { name: 'Productos', data: lista.map((x) => x.productos) }
        ],
        colors: [COLOR_PRIMARIO, '#57caeb'],
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
        dataLabels: { enabled: true, style: { fontSize: '10px' } },
        xaxis: { categories: lista.map((x) => recortar(x.competidor, 14)), labels: { style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: (v) => Math.round(v) } },
        legend: { position: 'top', fontSize: '12px' },
        grid: { borderColor: '#eef0f4' }
    }, lista.length > 0);
}

// ---------- Eventos ----------
document.addEventListener('DOMContentLoaded', () => {
    ['fDesde', 'fHasta', 'fCompetidor', 'fAgrupar'].forEach((id) => {
        el(id).addEventListener('change', () => {
            el('fProducto').value = ''; // el producto sugerido cambia con los filtros
            cargar();
        });
    });
    el('fProducto').addEventListener('change', cargar);
    el('btnLimpiar').addEventListener('click', limpiarFiltros);
    el('formFiltros').addEventListener('submit', (e) => { e.preventDefault(); cargar(); });
    document.querySelectorAll('[data-dias]').forEach((b) => b.addEventListener('click', () => aplicarAtajo(b.dataset.dias)));
    cargar();
});
