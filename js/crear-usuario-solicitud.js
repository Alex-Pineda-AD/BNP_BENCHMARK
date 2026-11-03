(function () {
    'use strict';
    const form = document.getElementById('crear-usuario-form');
    if (!form) return;
    const password = form.elements.password_nuevo;
    const confirmation = form.elements.password_confirmar;
    const feedback = document.getElementById('password-coincidencia');
    function validatePasswords() {
        const matches = password.value === confirmation.value;
        confirmation.setCustomValidity(confirmation.value && !matches ? 'Las contraseñas no coinciden.' : '');
        feedback.textContent = confirmation.value ? (matches ? 'Las contraseñas coinciden.' : 'Las contraseñas no coinciden.') : '';
        feedback.className = 'form-text ' + (matches ? 'text-success' : 'text-danger');
        return matches;
    }
    password.addEventListener('input', validatePasswords);
    confirmation.addEventListener('input', validatePasswords);
    form.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.dataset.togglePassword);
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', (visible ? 'Ocultar' : 'Mostrar') + (input === password ? ' contraseña inicial' : ' confirmación de contraseña'));
            button.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });
    form.addEventListener('submit', function (event) {
        if (!validatePasswords()) {
            event.preventDefault();
            confirmation.reportValidity();
            return;
        }
        const submit = document.getElementById('crear-usuario-submit');
        submit.disabled = true;
        submit.textContent = 'Creando cuenta…';
    });
    const search = document.getElementById('buscar-centros');
    if (search) {
        const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const items = [...document.querySelectorAll('#lista-centros li')];
        search.addEventListener('input', function () {
            const query = normalize(search.value.trim());
            let visible = 0;
            items.forEach(function (item) {
                item.hidden = !normalize(item.textContent).includes(query);
                if (!item.hidden) visible++;
            });
            document.getElementById('centros-sin-resultados').hidden = visible > 0;
        });
    }
})();
