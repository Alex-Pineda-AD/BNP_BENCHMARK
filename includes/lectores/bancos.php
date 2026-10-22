<?php

// El catálogo actual tiene ID y DESCRIPCION, pero no tiene un código técnico.
// Esta lista traduce nombres conocidos a lectores. Nunca se incluye un archivo
// usando directamente un valor del POST (por ejemplo, require $_POST['banco']).
function codigoLectorBanco(string $nombre): ?string
{
    $nombre = strtr(strtoupper(trim($nombre)), ['á' => 'A', 'Á' => 'A']);
    $nombres = [
        'BAC' => 'BAC', 'BAC CREDOMATIC' => 'BAC',
        'ATLANTIDA' => 'ATLANTIDA', 'BANCO ATLANTIDA' => 'ATLANTIDA',
        'FICOHSA' => 'FICOHSA', 'BANCO FICOHSA' => 'FICOHSA',
        'OCCIDENTE' => 'OCCIDENTE', 'BANCO DE OCCIDENTE' => 'OCCIDENTE',
    ];
    return $nombres[$nombre] ?? null;
}
