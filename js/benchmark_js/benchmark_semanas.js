// Selector de semana del año (ISO: lunes a domingo) para los filtros de Benchmark.
// Cada <div class="bm-semana" data-target="idInputOculto" data-edge="inicio|fin"> se convierte en dos selects
// (año + semana). El valor se guarda como fecha Y-m-d en el input oculto: lunes de la semana (inicio) o
// domingo de la semana, sin pasar de hoy (fin). Así el servidor sigue recibiendo "desde" y "hasta" como siempre.
//   data-vacio="Sin límite"  -> permite no elegir semana (filtro opcional)
//   data-par="idOtroInput"   -> mantiene desde <= hasta con el otro selector
(function () {
    'use strict';

    const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const parse = (s) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
        return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
    };
    const addDias = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
    const lunesDe = (d) => addDias(d, -((d.getDay() + 6) % 7));
    const hoyLocal = () => { const h = new Date(); return new Date(h.getFullYear(), h.getMonth(), h.getDate()); };

    // Semana ISO de una fecha: la semana pertenece al año de su jueves
    function isoSemana(d) {
        const jueves = addDias(lunesDe(d), 3);
        const anio = jueves.getFullYear();
        const dias = Math.round((jueves - new Date(anio, 0, 1)) / 864e5);
        return { anio: anio, semana: 1 + Math.floor(dias / 7) };
    }
    const lunesDeSemana = (anio, sem) => addDias(lunesDe(new Date(anio, 0, 4)), (sem - 1) * 7);
    const semanasEnAnio = (anio) => isoSemana(new Date(anio, 11, 28)).semana;
    const corto = (d) => d.getDate() + ' ' + MESES[d.getMonth()];
    const etiqueta = (anio, sem) => 'Sem ' + sem + ' · ' + corto(lunesDeSemana(anio, sem)) + ' – ' + corto(addDias(lunesDeSemana(anio, sem), 6));

    const pickers = [];

    function crear(cont) {
        const target = document.getElementById(cont.dataset.target);
        if (!target) return;
        const esFin = cont.dataset.edge === 'fin';
        const vacio = cont.dataset.vacio || '';
        const actual = isoSemana(hoyLocal());

        cont.classList.add('bm-semana-ui');
        const selA = document.createElement('select');
        const selS = document.createElement('select');
        selA.className = 'form-select form-select-sm bm-semana-anio';
        selS.className = 'form-select form-select-sm bm-semana-sem';
        selA.setAttribute('aria-label', 'Año');
        selS.setAttribute('aria-label', 'Semana');
        if (vacio) selA.add(new Option(vacio, ''));
        for (let a = actual.anio - 2; a <= actual.anio; a++) selA.add(new Option(String(a), String(a)));
        cont.append(selA, selS);

        function llenarSemanas(anio, elegida) {
            selS.innerHTML = '';
            if (!anio) { selS.disabled = true; return; }
            selS.disabled = false;
            const max = anio === actual.anio ? actual.semana : semanasEnAnio(anio);
            for (let s = 1; s <= max; s++) selS.add(new Option(etiqueta(anio, s), String(s)));
            selS.value = String(Math.min(Math.max(elegida || max, 1), max));
        }

        function valorActual() {
            if (!selA.value) return '';
            const l = lunesDeSemana(+selA.value, +selS.value);
            if (!esFin) return iso(l);
            const dom = addDias(l, 6), hoy = hoyLocal();
            return iso(dom > hoy ? hoy : dom);
        }

        // Lee el input oculto y refleja la semana en los selects (sin disparar eventos)
        cont._sync = function () {
            const f = parse(target.value);
            if (!f) {
                if (vacio) { selA.value = ''; llenarSemanas(''); target.value = ''; return; }
                const w = actual; selA.value = String(w.anio); llenarSemanas(w.anio, w.semana);
            } else {
                const w = isoSemana(f);
                if (![...selA.options].some((o) => o.value === String(w.anio))) selA.add(new Option(String(w.anio), String(w.anio)));
                selA.value = String(w.anio); llenarSemanas(w.anio, w.semana);
            }
            target.value = valorActual();
        };

        function alCambiar(e) {
            if (e) e.stopPropagation();
            if (e && e.target === selA) llenarSemanas(selA.value ? +selA.value : '', +selS.value || actual.semana);
            target.value = valorActual();
            // Mantener desde <= hasta con el selector pareja
            const otro = document.getElementById(cont.dataset.par || '');
            if (otro && target.value && otro.value) {
                const desde = esFin ? otro.value : target.value, hasta = esFin ? target.value : otro.value;
                if (desde > hasta) {
                    const l = lunesDe(parse(target.value));
                    otro.value = esFin ? iso(l) : iso(addDias(l, 6) > hoyLocal() ? hoyLocal() : addDias(l, 6));
                    const c = pickers.find((p) => p.dataset.target === otro.id);
                    if (c) c._sync();
                }
            }
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
        selA.addEventListener('change', alCambiar);
        selS.addEventListener('change', alCambiar);

        cont._sync();
        pickers.push(cont);
    }

    function iniciar() { document.querySelectorAll('.bm-semana').forEach(crear); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();

    // API para atajos ("esta semana", "últimas 4 semanas"...) y para "Limpiar"
    window.BenchmarkSemanas = {
        sincronizar: () => pickers.forEach((c) => c._sync()),
        // Rango de las últimas n semanas (la actual incluida): lunes de hace n-1 semanas hasta hoy
        ultimas: (n) => ({ desde: iso(addDias(lunesDe(hoyLocal()), -(n - 1) * 7)), hasta: iso(hoyLocal()) }),
        // Semana completa de hace n semanas (1 = la pasada): lunes a domingo
        pasada: (n) => { const l = addDias(lunesDe(hoyLocal()), -7 * n); return { desde: iso(l), hasta: iso(addDias(l, 6)) }; }
    };
})();
