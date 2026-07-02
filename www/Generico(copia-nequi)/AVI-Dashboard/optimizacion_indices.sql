-- ============================================
-- SENTENCIAS SQL PARA OPTIMIZACIÓN DE DASHBOARD
-- ============================================

-- El archivo se ejecuta en este orden:
-- 1. Índices para tablas principales
-- 2. Vistas materializadas
-- 3. Procedimientos de mantenimiento

-- ============================================
-- 1. CREAR ÍNDICES (Primero - para mejor rendimiento inmediato)
-- ============================================

-- Índices para tabla avi_calls
CREATE INDEX idx_avicalls_cliente_fecha_estado ON avi_calls(cliente, DATE(F9TimeStamp), Estado);
CREATE INDEX idx_avicalls_estado_fecha ON avi_calls(Estado, DATE(F9TimeStamp));

-- Índices para tabla avi_call_costs
CREATE INDEX idx_avicosts_cliente_fecha_duracion ON avi_call_costs(cliente, DATE(metadata_date_local), connection_duration_secs);
CREATE INDEX idx_avicosts_cliente_fecha_llm_cost ON avi_call_costs(cliente, DATE(metadata_date_local), llm_cost_total_usd);
CREATE INDEX idx_avicosts_f9_call_id ON avi_call_costs(f9_call_id);

-- Índices para siigo_lead_data_v2 (MUY IMPORTANTE)
CREATE INDEX idx_siigo_method_insertcall ON siigo_lead_data_v2(method, F9CallID) WHERE method = 'Insert Call';
CREATE INDEX idx_siigo_method_resultado ON siigo_lead_data_v2(method, F9CallID) WHERE method = 'ResultadoPerfil';
CREATE INDEX idx_siigo_key_values ON siigo_lead_data_v2(clave, valor) WHERE clave IN ('CLIENTE', 'F9TimeStamp', 'ANI', 'PROYECTO');
CREATE INDEX idx_siigo_fecha_valor ON siigo_lead_data_v2(clave, DATE(valor)) WHERE clave = 'F9TimeStamp';
CREATE INDEX idx_siigo_searchable ON siigo_lead_data_v2(clave, valor) WHERE clave IN ('ANI', 'PROYECTO', 'resultado_llamada');

-- ============================================
-- 2. CREAR VISTAS MATERIALIZADAS (Después - para consultas complejas)
-- ============================================

-- Vista para resumen diario de resultados
CREATE VIEW IF NOT EXISTS v_resumen_diario_resultados AS
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
CREATE VIEW IF NOT EXISTS v_consolidado_diario AS
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
CREATE VIEW IF NOT EXISTS v_tasa_retencion_diaria AS
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
-- 3. CREAR TABLA DE RESUMEN Opcional (si vistas no son suficientes)
-- ============================================

CREATE TABLE IF NOT EXISTS resumen_diario_kpis (
    fecha DATE PRIMARY KEY,
    llamadas_five9 INT DEFAULT 0,
    llamadas_avi INT DEFAULT 0,
    duracion_total_segundos INT DEFAULT 0,
    costo_llm_total DECIMAL(10,4) DEFAULT 0,
    retencion_total INT DEFAULT 0,
    tasa_retencion DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Procedimiento para actualizar la tabla de resumen (opcional)
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
-- 4. INSTRUCCIONES DE MANTENIMIENTO
-- ============================================

-- Para actualizar las vistas materializadas (MySQL las actualiza automáticamente)
-- Si se usa la tabla resumen_diario_kpis, ejecutar:
-- CALL actualizar_resumen_diario();

-- Crear un job en el scheduler para ejecutar semanalmente
-- Ejecutar este comando en tu scheduler semanalmente:
-- CALL actualizar_resumen_diario();

-- O usando crontab (Linux):
# 0 2 * * 0 mysql -u tu_usuario -p tu_contraseña tu_base_datos -e "CALL actualizar_resumen_diario();"

-- ============================================
-- 5. CONSULTAS DE EJEMPLO DESPUÉS DE LA OPTIMIZACIÓN
-- ============================================

-- Ejemplo: Obtener resumen de hoy usando vista
-- SELECT * FROM v_resumen_diario_resultados WHERE fecha = CURDATE();

-- Ejemplo: Obtener KPIs históricos usando vista
-- SELECT * FROM v_consolidado_diario
-- WHERE fecha BETWEEN '2026-01-01' AND '2026-01-31';

-- Ejemplo: Obtener tasa de retención
-- SELECT * FROM v_tasa_retencion_diaria
-- WHERE fecha BETWEEN '2026-01-01' AND '2026-01-31';

-- ============================================
-- FIN
-- ============================================

-- Para ejecutar:
-- 1. Guardar este archivo como optimizacion_indices.sql
-- 2. Conectarse a la base de datos MySQL
-- 3. Ejecutar: source optimizacion_indices.sql;