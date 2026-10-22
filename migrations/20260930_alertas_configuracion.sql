-- Una regla por pantalla y estado. Los plazos se configuran antes de activarla.
CREATE TABLE IF NOT EXISTS alertas_configuracion (
    ID BIGINT(20) NOT NULL AUTO_INCREMENT,
    PANTALLA VARCHAR(20) NOT NULL COMMENT 'DEPOSITOS o VALIDACION',
    ID_ESTADO BIGINT(20) NOT NULL,
    PLAZO_DIAS INT UNSIGNED DEFAULT NULL,
    MENSAJE VARCHAR(250) NOT NULL,
    ESTADO TINYINT NOT NULL DEFAULT 0 COMMENT '0 desactivada, 1 activa',
    USUARIO_MODIFICO BIGINT(20) DEFAULT NULL,
    FECHA_MODIFICO DATETIME DEFAULT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY uq_alerta_pantalla_estado (PANTALLA, ID_ESTADO),
    CONSTRAINT fk_alerta_estado FOREIGN KEY (ID_ESTADO) REFERENCES estados (ID),
    CONSTRAINT fk_alerta_usuario FOREIGN KEY (USUARIO_MODIFICO) REFERENCES usuarios (ID),
    CONSTRAINT chk_alerta_pantalla CHECK (PANTALLA IN ('DEPOSITOS','VALIDACION')),
    CONSTRAINT chk_alerta_activa CHECK (ESTADO IN (0,1)),
    CONSTRAINT chk_alerta_plazo CHECK ((PLAZO_DIAS IS NULL OR PLAZO_DIAS > 0) AND (ESTADO = 0 OR PLAZO_DIAS IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO alertas_configuracion (PANTALLA, ID_ESTADO, MENSAJE)
SELECT 'DEPOSITOS', ID, 'El deposito tiene una correccion pendiente fuera de plazo.'
FROM estados WHERE ID = 7
AND NOT EXISTS (SELECT 1 FROM alertas_configuracion WHERE PANTALLA='DEPOSITOS' AND ID_ESTADO=7);

INSERT INTO alertas_configuracion (PANTALLA, ID_ESTADO, MENSAJE)
SELECT 'VALIDACION', ID, 'El deposito esta pendiente de validacion fuera de plazo.'
FROM estados WHERE ID = 2
AND NOT EXISTS (SELECT 1 FROM alertas_configuracion WHERE PANTALLA='VALIDACION' AND ID_ESTADO=2);
