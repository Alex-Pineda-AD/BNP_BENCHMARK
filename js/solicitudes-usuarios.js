(function () {
    'use strict';
    const select = document.getElementById('centros-solicitud');
    if (!select || select.disabled || !window.Choices) return;
    // Los centros son opcionales para las cuentas de Benchmark (data-requerido="0").
    const requerido = select.dataset.requerido !== '0';
    new Choices(select, {
        removeItemButton: true,
        shouldSort: false,
        allowHTML: false,
        labelId: 'centros-solicitud-label',
        searchEnabled: true,
        searchResultLimit: 50,
        placeholder: true,
        placeholderValue: 'Selecciona centros de venta',
        searchPlaceholderValue: 'Buscar centro de venta',
        noResultsText: 'No se encontraron centros',
        noChoicesText: 'No quedan centros por seleccionar',
        itemSelectText: 'Seleccionar'
    });
    // Choices oculta el select; mostramos el error en el control visible.
    select.required = false;
    const error = document.getElementById('centros-error');
    const search = select.closest('.choices').querySelector('input');
    search.setAttribute('aria-describedby', 'centros-ayuda centros-error');
    function validate() {
        const valid = !requerido || select.selectedOptions.length > 0;
        error.hidden = valid;
        search.setAttribute('aria-invalid', valid ? 'false' : 'true');
        return valid;
    }
    select.addEventListener('change', validate);
    select.form.addEventListener('submit', function (event) {
        if (!validate()) {
            event.preventDefault();
            search.focus();
        }
    });
})();
