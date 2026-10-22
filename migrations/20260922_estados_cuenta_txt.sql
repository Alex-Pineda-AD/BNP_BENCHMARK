-- Ejecutar una sola vez en la base conciliacion, antes de usar el formulario.
CREATE TABLE estados_cuenta_txt (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    hash_archivo CHAR(64) NOT NULL UNIQUE,
    archivo_original MEDIUMBLOB NOT NULL,
    numero_cliente VARCHAR(50) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    producto VARCHAR(50) NOT NULL,
    moneda VARCHAR(10) NOT NULL,
    saldo_inicial DECIMAL(15,2) NOT NULL,
    saldo_libros DECIMAL(15,2) NOT NULL,
    retenidos_diferidos DECIMAL(15,2) NOT NULL,
    saldo_disponible DECIMAL(15,2) NOT NULL,
    fecha DATE NOT NULL,
    stbgav DECIMAL(15,2) NOT NULL,
    stbunc DECIMAL(15,2) NOT NULL,
    mensaje1 TEXT NOT NULL,
    mensaje2 TEXT NOT NULL,
    mensaje3 TEXT NOT NULL,
    mensaje4 TEXT NOT NULL,
    mensaje5 TEXT NOT NULL,
    mensaje6 TEXT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transacciones_estado_txt (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_cuenta_id BIGINT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    referencia VARCHAR(100) NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    descripcion TEXT NOT NULL,
    debito DECIMAL(15,2) NOT NULL,
    credito DECIMAL(15,2) NOT NULL,
    balance DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (estado_cuenta_id) REFERENCES estados_cuenta_txt(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE resumen_estado_txt (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_cuenta_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    cantidad_debitos INT UNSIGNED NOT NULL,
    monto_debitos DECIMAL(15,2) NOT NULL,
    cantidad_creditos INT UNSIGNED NOT NULL,
    monto_creditos DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (estado_cuenta_id) REFERENCES estados_cuenta_txt(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
