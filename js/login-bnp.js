(() => {
    const form = document.getElementById('login-form');
    const password = document.getElementById('login-password');
    const toggle = document.getElementById('toggle-password');
    const submit = document.getElementById('login-submit');

    // Mostrar / ocultar contraseña
    toggle.addEventListener('click', () => {
        const visible = password.type === 'text';
        password.type = visible ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!visible));
        toggle.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
        toggle.firstElementChild.className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
        password.focus();
    });

    // Estado de envío (evita doble clic)
    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        submit.disabled = true;
        submit.classList.add('is-loading');
    });
})();
