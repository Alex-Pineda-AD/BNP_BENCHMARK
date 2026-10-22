(function () {
    'use strict';
    var deposits = document.getElementById('buscar-deposito');
    if (deposits) deposits.addEventListener('input', function () {
        var term = this.value.toLowerCase().trim();
        document.querySelectorAll('.cruce-deposito-fila').forEach(function (item) {
            item.hidden = item.textContent.toLowerCase().indexOf(term) === -1;
        });
    });
    var table = document.getElementById('tabla-movimientos');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var search = document.getElementById('buscar-movimiento');
    var previous = document.getElementById('mov-anterior');
    var next = document.getElementById('mov-siguiente');
    var page = 0, size = 25;
    function render() {
        var term = search.value.toLowerCase().trim();
        var filtered = rows.filter(function (row) { return row.textContent.toLowerCase().indexOf(term) !== -1; });
        var pages = Math.max(1, Math.ceil(filtered.length / size));
        page = Math.min(page, pages - 1);
        rows.forEach(function (row) { row.hidden = true; });
        filtered.slice(page * size, (page + 1) * size).forEach(function (row) { row.hidden = false; });
        document.getElementById('mov-pagina').textContent = filtered.length + ' movimientos · Página ' + (page + 1) + ' de ' + pages;
        previous.disabled = page === 0;
        next.disabled = page >= pages - 1;
    }
    search.addEventListener('input', function () { page = 0; render(); });
    previous.addEventListener('click', function () { if (page > 0) page--; render(); });
    next.addEventListener('click', function () { page++; render(); });
    render();
}());
