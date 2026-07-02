-- ============================================
-- SENTENCIAS SQL CORREGIDAS PARA OPTIMIZACIÓN
-- ============================================

-- El error fue: MySQL no permite DATE() en índices
-- Solución: Usar columnas reales y crear índices adicionales

-- ============================================
-- 1. CREAR ÍNDICES CORREGIDOS
-- ============================================

-- Índices para tabla avi_calls (sin funciones en índices)
CREATE INDEX idx_avicalls_cliente ON avi_calls(cliente);
CREATE INDEX idx_avicalls_estado ON avi_calls(Estado);
CREATE INDEX idx_avicalls_fecha ON avi_calls(F9TimeStamp);
CREATE INDEX idx_avicalls_cliente_estado ON avi_calls(cliente, Estado);
CREATE INDEX idx_avicalls_fecha_estado ON avi_calls(F9TimeStamp, Estado);

-- Índices para tabla avi_call_costs
CREATE INDEX idx_avicosts_cliente ON avi_call_costs(cliente);
CREATE INDEX idx_avicosts_fecha ON avi_call_costs(metadata_date_local);
CREATE INDEX idx_avicosts_f9_call_id ON avi_call_costs(f9_call_id);
CREATE INDEX idx_avicosts_cliente_fecha ON avi_call_costs(cliente, metadata_date_local);
CREATE INDEX idx_avicosts_fecha_duracion ON avi_call_costs(metadata_date_local, connection_duration_secs);
CREATE INDEX idx_avicosts_fecha_costo ON avi_call_costs(metadata_date_local, llm_cost_total_usd);

-- Índices para siigo_lead_data_v2
CREATE INDEX idx_siigo_f9callid ON siigo_lead_data_v2(F9CallID);
CREATE INDEX idx_siigo_method ON siigo_lead_data_v2(method);
CREATE INDEX idx_siigo_metodo_f9callid ON siigo_lead_data_v2(method, F9CallID);
CREATE INDEX idx_siigo_clave ON siigo_lead_data_v2(clave);
CREATE INDEX idx_siigo_valor ON siigo_lead_data_v2(valor);
CREATE INDEX idx_siigo_metodo_clave ON siigo_lead_data_v2(method, clave);
CREATE INDEX idx_siigo_f9callid_clave_valor ON siigo_lead_data_v2(F9CallID, clave, valor);

-- Índices específicos para consultas comunes
CREATE INDEX idx_siigo_insertcall_cliente ON siigo_lead_data_v2(method, cliente) WHERE method = 'Insert Call';
CREATE INDEX idx_siigo_resultado_cliente ON siigo_lead_data_v2(method, cliente) WHERE method = 'ResultadoPerfil';

-- ============================================
-- 2. VISTAS MATERIALIZADAS
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
    SELECT DISTINCT F9CallID as f9_call_id, DATE(F9TimeStamp) as fecha
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
-- 3. TABLA DE RESUMEN OPCIONAL
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Procedimiento para actualizar la tabla de resumen
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS actualizar_resumen_diario()
BEGIN
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
        END) * 100.0 / COUNT(DISTINCT ac.f9_call_id)), 2) as tasa_retencion
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
END //
DELIMITER ;

-- ============================================
-- 4. INSTRUCCIONES Y CONSULTAS DE EJEMPLO
-- ============================================

-- Para actualizar las vistas (MySQL las actualiza automáticamente)
-- Si se usa la tabla resumen_diario_kpis, ejecutar:
-- CALL actualizar_resumen_diario();

-- Consulta de ejemplo para obtener resumen de hoy
-- SELECT * FROM v_resumen_diario_resultados WHERE fecha = CURDATE();

-- Consulta de ejemplo para obtener KPIs históricos
-- SELECT * FROM v_consolidado_diario
-- WHERE fecha BETWEEN '2026-01-01' AND '2026-01-31';

-- Consulta de ejemplo para obtener tasa de retención
-- SELECT * FROM v_tasa_retencion_diaria
-- WHERE fecha BETWEEN '2026-01-01' AND '2026-01-31';

-- ============================================
-- 5. CONSULTAS OPTIMIZADAS PARA USAR EN PHP
-- ============================================

-- Query optimizado para KPIs de hoy
-- SELECT
--     COUNT(*) as total_calls_five9,
--     (SELECT COUNT(*) FROM avi_call_costs WHERE cliente = 'NEQUI' AND DATE(metadata_date_local) = CURDATE()) as total_calls_avi,
--     (SELECT SUM(connection_duration_secs) FROM avi_call_costs WHERE cliente = 'NEQUI' AND DATE(metadata_date_local) = CURDATE()) as total_duration,
--     (SELECT SUM(llm_cost_total_usd) FROM avi_call_costs WHERE cliente = 'NEQUI' AND DATE(metadata_date_local) = CURDATE()) as total_cost
-- FROM avi_calls
-- WHERE cliente = 'NEQUI' AND DATE(F9TimeStamp) = CURDATE();

-- Query optimizado para resumen diario con filtros
-- SELECT
--     fecha,
--     llamadas_five9,
--     llamadas_avi,
--     ROUND(duracion_total_segundos/60, 2) as duracion_minutos,
--     ROUND(costo_llm_total, 3) as costo_llm_total,
--     tasa_retencion
-- FROM resumen_diary_kpis
-- WHERE fecha BETWEEN :fecha_desde AND :fecha_hasta
-- ORDER BY fecha;

-- ============================================
-- FIN
-- ============================================

-- Para ejecutar:
-- 1. Guardar este archivo como optimizacion_indices_corregido.sql
-- 2. Conectarse a la base de datos MySQL:
--    mysql -u usuario -p base_datos < optimizacion_indices_corregido.sql
-- 3. Probar las consultas de ejemplo