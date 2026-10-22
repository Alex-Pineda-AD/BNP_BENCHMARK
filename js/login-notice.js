(() => {
    const params = new URLSearchParams(location.search);
    const message = params.get('sesion') === 'requerida'
        ? 'Iniciá sesión para acceder al sistema. Cada navegador mantiene su propia sesión.'
        : params.get('error');
    if (message) {
        const notice = document.getElementById('login-notice');
        notice.textContent = message;
        notice.hidden = false;
    }
})();
