-- Conservamos los IDs del catalogo existente. No duplicamos POR CORREGIR.
INSERT INTO estados (DESCRIPCION)
SELECT 'POR CORREGIR'
WHERE NOT EXISTS (
    SELECT 1 FROM estados WHERE DESCRIPCION = 'POR CORREGIR'
);

-- Cada accion agrega una fila nueva; el historial no se sobrescribe.
-- ESTADO_ANTERIOR queda NULL en el primer envio del deposito.
-- ID_DEPOSITO_DETALLE identifica el comprobante revisado, si corresponde.
CREATE TABLE IF NOT EXISTS depositos_bitacora (
    ID BIGINT(20) NOT NULL AUTO_INCREMENT,
    ID_DEPOSITO BIGINT(20) NOT NULL,
    ID_DEPOSITO_DETALLE BIGINT(20) DEFAULT NULL,
    ACCION VARCHAR(50) NOT NULL COMMENT 'ENVIO, SOLICITUD_CORRECCION, REENVIO, REVISION, APROBACION, ANULACION o CIERRE',
    ESTADO_ANTERIOR BIGINT(20) DEFAULT NULL,
    ESTADO_NUEVO BIGINT(20) NOT NULL,
    OBSERVACION VARCHAR(500) DEFAULT NULL,
    USUARIO_CREO BIGINT(20) NOT NULL,
    FECHA_CREO DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ID),
    KEY idx_bitacora_deposito_fecha (ID_DEPOSITO, FECHA_CREO, ID),
    CONSTRAINT fk_bitacora_deposito FOREIGN KEY (ID_DEPOSITO)
        REFERENCES depositos (ID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bitacora_detalle FOREIGN KEY (ID_DEPOSITO_DETALLE)
        REFERENCES depositos_detalle (ID_DEPOSITO_DETALLE) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bitacora_estado_anterior FOREIGN KEY (ESTADO_ANTERIOR)
        REFERENCES estados (ID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bitacora_estado_nuevo FOREIGN KEY (ESTADO_NUEVO)
        REFERENCES estados (ID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bitacora_usuario FOREIGN KEY (USUARIO_CREO)
        REFERENCES usuarios (ID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Al implementar cada accion en PHP, actualizar depositos.ESTADO e insertar
-- su bitacora dentro de la misma transaccion. Si se indica un detalle,
-- validar que pertenezca al deposito. Guardar nuevas fotos como nuevos detalles.
-- Esta migracion no reconstruye eventos pasados ni agrega eventos automaticos.
