<?php
// Control de acceso y token CSRF compartidos por las páginas y controladores de Benchmark.
// Requiere session_start() previo.

const BENCHMARK_ROLES = [5, 6];

function benchmark_tiene_acceso(): bool
{
    return !empty($_SESSION['user_id'])
        && in_array((int)($_SESSION['id_rol'] ?? 0), BENCHMARK_ROLES, true);
}

// Páginas: sin sesión va al login; sin rol permitido responde 403
function benchmark_requerir_pagina(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ../../index.html?sesion=requerida');
        exit;
    }
    if (!benchmark_tiene_acceso()) {
        http_response_code(403);
        exit('No tenés permiso para acceder a Benchmark.');
    }
}

function benchmark_csrf_token(): string
{
    if (empty($_SESSION['benchmark_csrf'])) {
        $_SESSION['benchmark_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['benchmark_csrf'];
}

function benchmark_csrf_valido($token): bool
{
    return is_string($token)
        && !empty($_SESSION['benchmark_csrf'])
        && hash_equals($_SESSION['benchmark_csrf'], $token);
}
