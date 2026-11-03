<?php
// Roles que cada rol de sesión puede ver, asignar y gestionar en el módulo de Usuarios.
// null = sin restricción (roles 4 y 5, igual que antes).
// El rol 6 (Administrador Benchmark) solo gestiona usuarios de Benchmark: roles 6 y 7.
const USUARIOS_ROLES_ADMIN_BENCHMARK = [6, 7];

function usuarios_roles_gestionables(?int $rolSesion = null): ?array
{
    $rol = $rolSesion ?? (int) ($_SESSION['id_rol'] ?? 0);
    return $rol === 6 ? USUARIOS_ROLES_ADMIN_BENCHMARK : null;
}

// Fragmento SQL "AND <columna> IN (...)" para filtrar por rol; vacío si no hay restricción.
// $columna es siempre un literal del código (nunca dato del usuario); los roles son enteros.
function usuarios_roles_sql(string $columna): string
{
    $roles = usuarios_roles_gestionables();
    return $roles === null ? '' : 'AND ' . $columna . ' IN (' . implode(',', array_map('intval', $roles)) . ')';
}
