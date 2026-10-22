(() => {
    'use strict';
    const select = document.getElementById('ID_CENTRO_VENTA');
    const filter = document.getElementById('centro-id');
    const help = document.getElementById('centro-help');
    const centers = Array.from(select.options).filter(option => option.value).map(option => ({ id: option.value, text: option.textContent }));
    const available = centers.length > 0;
    const enhanced = !!window.jQuery?.fn.select2;
    const $select = enhanced ? window.jQuery(select) : null;
    if (enhanced) {
        $select.select2({
            width: '100%',
            language: 'es',
            placeholder: 'Buscar centro por nombre o ID',
            allowClear: true,
            // Keep the list beside the field: the template uses zoom: .8.
            dropdownParent: window.jQuery('#centro-select-wrapper'),
        });
        $select.on('select2:open', () => {
            document.querySelector('#centro-select-wrapper .select2-search__field')?.focus();
        });
    }
    function options(items, selected = '') {
        select.replaceChildren(new Option('', ''));
        items.forEach(item => select.add(new Option(item.text, item.id)));
        select.value = selected;
        if (enhanced) $select.trigger('change.select2');
    }
    function notify() { select.dispatchEvent(new Event('input', { bubbles: true })); }
    function selected() {
        const id = select.value;
        options(centers, id);
        filter.value = id;
        if (available) help.textContent = id ? `Seleccionado: ${select.options[select.selectedIndex].textContent}` : 'Buscá por nombre o escribí el ID. Solo se muestran tus centros asignados.';
        notify();
    }
    if (enhanced) $select.on('change', selected);
    else select.addEventListener('change', selected);
    filter.addEventListener('input', () => {
        const id = filter.value.trim();
        const matches = /^\d*$/.test(id) ? centers.filter(center => center.id.startsWith(id)) : [];
        const exact = matches.find(center => center.id === id);
        options(matches, exact?.id || '');
        help.textContent = exact ? `Seleccionado: ${exact.text}` : matches.length ? `${matches.length} centro(s) disponible(s). Completá el ID o elegí uno en el selector.` : 'No hay centros asignados que coincidan con ese ID.';
        notify();
    });
    filter.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); if (enhanced && !select.value) $select.select2('open'); } });
    window.depositoCentro = {
        restore(id) { options(centers, id); selected(); },
        disable(disabled) {
            const locked = disabled || !available;
            // Avoid notifying Select2 again on every keystroke in its search box.
            if (select.disabled !== locked) select.disabled = locked;
            filter.disabled = locked;
            if (enhanced && locked) $select.select2('close');
        },
    };
})();
