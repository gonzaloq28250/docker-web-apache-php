-- ============================================
-- SENTENCIAS SQL CORREGIDAS PARA MYSQL
-- ============================================

-- Errores corregidos:
-- 1. TEXT/BLOB columns necesitan longitud de clave
-- 2. MySQL no soporta WHERE en CREATE INDEX

-- ============================================
-- 1. ÍNDICES PARA avi_calls
-- ============================================

CREATE INDEX idx_avicalls_cliente ON avi_calls(cliente);
CREATE INDEX idx_avicalls_estado ON avi_calls(Estado);
CREATE INDEX idx_avicalls_fecha ON avi_calls(F9TimeStamp);
CREATE INDEX idx_avicalls_cliente_estado ON avi_calls(cliente, Estado);
CREATE INDEX idx_avicalls_fecha_estado ON avi_calls(F9TimeStamp, Estado);

-- ============================================
-- 2. ÍNDICES PARA avi_call_costs
-- ============================================

CREATE INDEX idx_avicosts_cliente ON avi_call_costs(cliente);
CREATE INDEX idx_avicosts_fecha ON avi_call_costs(metadata_date_local);
CREATE INDEX idx_avicosts_f9_call_id ON avi_call_costs(f9_call_id);
CREATE INDEX idx_avicosts_cliente_fecha ON avi_call_costs(cliente, metadata_date_local);
CREATE INDEX idx_avicosts_fecha_duracion ON avi_call_costs(metadata_date_local, connection_duration_secs);
CREATE INDEX idx_avicosts_fecha_costo ON avi_call_costs(metadata_date_local, llm_cost_total_usd);

-- ============================================
-- 3. ÍNDICES PARA siigo_lead_data_v2 (CORREGIDO)
-- ============================================

-- Índices básicos sin columnas TEXT
CREATE INDEX idx_siigo_f9callid ON siigo_lead_data_v2(F9CallID);
CREATE INDEX idx_siigo_method ON siigo_lead_data_v2(method);
CREATE INDEX idx_siigo_metodo_f9callid ON siigo_lead_data_v2(method, F9CallID);
CREATE INDEX idx_siigo_clave ON siigo_lead_data_v2(clave);

-- NOTA: No crear índices en 'valor' porque es TEXT
-- Para búsquedas por valor, usar prefix index si es necesario:
-- CREATE INDEX idx_siigo_valor_prefix ON siigo_lead_data_v2(valor(50));

-- Índice compuesto para consultas principales
CREATE INDEX idx_siigo_metodo_clave_f9callid ON siigo_lead_data_v2(method, clave, F9CallID);

-- ============================================
-- 4. VISTAS MATERIALIZADAS
-- ============================================

-- Vista para resumen diario de resultados
CREATE OR REPLACE VIEW v_resumen_diario_resultados AS
SELECT
    DATE(ts.valor) as fecha,
    res.valor as resultado,
    COUNT(DISTINCT r.F9CallID) as total_llamadas
FROM siigo_lead_data_v2 r
INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID AND ts.clave = 'F9TimeStamp'
INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID AND res.clave = 'resultado_llamada'
WHERE r.method = 'Insert Call'
    AND r.cliente = 'NEQUI'
    AND ts.valor IS NOT NULL
GROUP BY DATE(ts.valor), res.valor;

-- Vista para consolidado diario
CREATE OR REPLACE VIEW v_consolidado_diario AS
SELECT
    DATE(f9.fecha) as fecha,
    COUNT(DISTINCT f9.f9_call_id) as llamadas_five9,
    COUNT(DISTINCT ac.f9_call_id) as llamadas_avi,
    SUM(ac.connection_duration_secs) as duracion_total,
    SUM(ac.llm_cost_total_usd) as costo_llm_total
FROM (
    SELECT DISTINCT F9CallID as f9_call_id, F9TimeStamp as fecha
    FROM avi_calls
    WHERE cliente = 'NEQUI'
) f9
LEFT JOIN avi_call_costs ac ON f9.f9_call_id = ac.f9_call_id
GROUP BY DATE(f9.fecha);

-- Vista para tasa de retención diaria
CREATE OR REPLACE VIEW v_tasa_retencion_diaria AS
SELECT
    fecha,
    llamadas_avi,
    retencion_total,
    ROUND((retencion_total * 100.0 / llamadas_avi), 2) as tasa_retencion_porc
FROM (
    SELECT
        DATE(ts.valor) as fecha,
        COUNT(DISTINCT r.F9CallID) as total_llamadas,
        COUNT(DISTINCT ac.f9_call_id) as llamadas_avi,
        SUM(CASE
            WHEN res.valor IN ('contencion_exitosa', 'consulta_resuelta', 'consulta_resuelta_pase_asesor')
            THEN 1 ELSE 0
        END) as retencion_total
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID AND ts.clave = 'F9TimeStamp'
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID AND res.clave = 'resultado_llamada'
    LEFT JOIN avi_call_costs ac ON r.F9CallID = ac.f9_call_id
    WHERE r.method = 'Insert Call'
        AND r.cliente = 'NEQUI'
        AND ts.valor IS NOT NULL
    GROUP BY DATE(ts.valor)
) sub
WHERE llamadas_avi > 0;

-- ============================================
-- 5. TABLA DE RESUMEN OPCIONAL
-- ============================================

DROP TABLE IF EXISTS resumen_diario_kpis;

CREATE TABLE resumen_diario_kpis (
    fecha DATE PRIMARY KEY,
    llamadas_five9 INT DEFAULT 0,
    llamadas_avi INT DEFAULT 0,
    duracion_total_segundos INT DEFAULT 0,
    costo_llm_total DECIMAL(10,4) DEFAULT 0,
    retencion_total INT DEFAULT 0,
    tasa_retencion DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fecha_kpi (fecha)
);

-- Procedimiento para actualizar la tabla de resumen
DROP PROCEDURE IF EXISTS actualizar_resumen_diario;

DELIMITER //
CREATE PROCEDURE actualizar_resumen_diario()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        -- Rollback en caso de error
        ROLLBACK;
        SELECT 'Error en actualización de resumen diario' as message;
    END;

    START TRANSACTION;

    -- Borrar registros viejos (más de 90 días)
    DELETE FROM resumen_diario_kpis WHERE fecha < DATE_SUB(CURDATE(), INTERVAL 90 DAY);

    -- Insertar o actualizar resumen diario
    INSERT INTO resumen_diario_kpis (
        fecha, llamadas_five9, llamadas_avi, duracion_total_segundos,
        costo_llm_total, retencion_total, tasa_retencion
    )
    SELECT
        DATE(ts.valor) as fecha,
        COUNT(DISTINCT r.F9CallID) as llamadas_five9,
        COUNT(DISTINCT ac.f9_call_id) as llamadas_avi,
        COALESCE(SUM(ac.connection_duration_secs), 0) as duracion_total_segundos,
        COALESCE(SUM(ac.llm_cost_total_usd), 0) as costo_llm_total,
        SUM(CASE
            WHEN res.valor IN ('contencion_exitosa', 'consulta_resuelta', 'consulta_resuelta_pase_asesor')
            THEN 1 ELSE 0
        END) as retencion_total,
        ROUND((SUM(CASE
            WHEN res.valor IN ('contencion_exitosa', 'consulta_resuelta', 'consulta_resuelta_pase_asesor')
            THEN 1 ELSE 0
        END) * 100.0 / NULLIF(COUNT(DISTINCT ac.f9_call_id), 0)), 2) as tasa_retencion
    FROM siigo_lead_data_v2 r
    INNER JOIN siigo_lead_data_v2 ts ON r.F9CallID = ts.F9CallID AND ts.clave = 'F9TimeStamp'
    INNER JOIN siigo_lead_data_v2 res ON r.F9CallID = res.F9CallID AND res.clave = 'resultado_llamada'
    LEFT JOIN avi_call_costs ac ON r.F9CallID = ac.f9_call_id
    WHERE r.method = 'Insert Call'
        AND r.cliente = 'NEQUI'
        AND ts.valor >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY DATE(ts.valor)
    ON DUPLICATE KEY UPDATE
        llamadas_five9 = VALUES(llamadas_five9),
        llamadas_avi = VALUES(llamadas_avi),
        duracion_total_segundos = VALUES(duracion_total_segundos),
        costo_llm_total = VALUES(costo_llm_total),
        retencion_total = VALUES(retencion_total),
        tasa_retencion = VALUES(tasa_retencion);

    COMMIT;
    SELECT 'Resumen diario actualizado correctamente' as message, ROW_COUNT() as rows_affected;
END //
DELIMITER ;

-- ============================================
-- 6. CONSULTAS OPTIMIZADAS PARA PHP
-- ============================================

-- Para verificar que los índices están creados
-- SHOW INDEX FROM siigo_lead_data_v2;
-- SHOW INDEX FROM avi_calls;
-- SHOW INDEX FROM avi_call_costs;

-- Consulta de ejemplo para usar en dashboard_consolidado.php
-- En lugar de:
-- SELECT ... FROM siigo_lead_data_v2 WHERE method = 'Insert Call' AND EXISTS (...)
-- Usar:
-- SELECT ... FROM siigo_lead_data_v2
-- WHERE method = 'Insert Call' AND F9CallID IN (
--     SELECT F9CallID FROM siigo_lead_data_v2
--     WHERE clave = 'CLIENTE' AND valor = 'NEQUI'
-- )

-- ============================================
-- FIN
-- ============================================

-- Para ejecutar:
-- 1. Guardar este archivo como optimizacion_indices_final.sql
-- 2. Conectarse a la base de datos:
--    mysql -u usuario -p base_datos < optimizacion_indices_final.sql
-- 3. Verificar los índices creados:
--    SHOW INDEX FROM siigo_lead_data_v2;