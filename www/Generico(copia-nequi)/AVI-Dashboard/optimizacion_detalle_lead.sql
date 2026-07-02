-- ============================================
-- ÍNDICES ESPECÍFICOS PARA detalle_lead_v2.php
-- ============================================

-- ============================================
-- 1. ÍNDICES PARA siigo_lead_data_v2 (CRÍTICOS)
-- ============================================

-- Índice compuesto para búsqueda por F9CallID y método
CREATE INDEX idx_siigo_f9callid_method ON siigo_lead_data_v2(F9CallID, method);

-- Índice para obtener F911DNIS rápidamente
CREATE INDEX idx_siigo_f9callid_clave_dnis ON siigo_lead_data_v2(F9CallID, clave) WHERE clave = 'F911DNIS';

-- Índice para búsqueda por F9CallID (más específico)
CREATE INDEX idx_siigo_f9callid_specific ON siigo_lead_data_v2(F9CallID, clave, method);

-- ============================================
-- 2. ÍNDICES PARA avi_call_costs (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por f9_call_id
CREATE INDEX idx_avicosts_f9id ON avi_call_costs(f9_call_id);

-- Índice compuesto para obtener duración y costo
CREATE INDEX idx_avicosts_f9id_duracion_costo ON avi_call_costs(f9_call_id, connection_duration_secs, llm_cost_total_usd);

-- ============================================
-- 3. ÍNDICES PARA eleven_n8n_t1 (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por from_number (DNIS)
CREATE INDEX idx_eleven_from_number ON eleven_n8n_t1(from_number);

-- Índice compuesto para obtener conversaciones
CREATE INDEX idx_eleven_from_created ON eleven_n8n_t1(from_number, created_at);

-- Índice para ElevenConversationID
CREATE INDEX idx_eleven_conversation_id ON eleven_n8n_t1(ElevenConversationID);

-- ============================================
-- 4. ÍNDICES PARA eleven_n8n_t1_analisis (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por ElevenConversationID
CREATE INDEX idx_eleven_analisis_conversation ON eleven_n8n_t1_analisis(ElevenConversationID);

-- Índice compuesto para análisis
CREATE INDEX idx_eleven_analisis_conversation_result ON eleven_n8n_t1_analisis(ElevenConversationID, result, criteria_id);

-- ============================================
-- 5. VISTA OPTIMIZADA PARA DETALLE LEAD
-- ============================================

-- Vista que pre-compila los datos necesarios para detalle_lead_v2
CREATE OR REPLACE VIEW v_detalle_lead_data AS
SELECT
    sld.F9CallID,
    sld.method,
    sld.clave,
    sld.valor,
    acc.connection_duration_secs,
    acc.llm_cost_total_usd,
    ent.ElevenConversationID
FROM siigo_lead_data_v2 sld
LEFT JOIN avi_call_costs acc ON sld.F9CallID = acc.f9_call_id
LEFT JOIN (
    SELECT DISTINCT from_number, ElevenConversationID
    FROM eleven_n8n_t1
) ent ON sld.valor = ent.from_number AND sld.clave = 'F911DNIS'
WHERE sld.method IN ('Insert Call', 'ResultadoPerfil');

-- ============================================
-- 6. PROCEDIMIENTO PARA CACHE DE DETALLES FRECUENTES
-- ============================================

-- Tabla de caché para detalles de leads más frecuentes
CREATE TABLE IF NOT EXISTS cache_detalle_leads (
    F9CallID VARCHAR(100) PRIMARY KEY,
    datos_insert_call JSON,
    datos_resultado_perfil JSON,
    costos JSON,
    ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    veces_accedido INT DEFAULT 1,
    INDEX idx_ultima_actualizacion (ultima_actualizacion),
    INDEX idx_veces_accedido (veces_accedido)
);

-- Procedimiento para obtener detalle desde caché o desde DB
DELIMITER //
CREATE PROCEDURE sp_obtener_detalle_lead(IN p_f9callid VARCHAR(100))
BEGIN
    DECLARE v_cached INT DEFAULT 0;
    DECLARE v_datos_json JSON;

    -- Verificar si está en caché y es reciente (menos de 1 hora)
    SELECT COUNT(*) INTO v_cached
    FROM cache_detalle_leads
    WHERE F9CallID = p_f9callid
        AND ultima_actualizacion > DATE_SUB(NOW(), INTERVAL 1 HOUR);

    IF v_cached > 0 THEN
        -- Retornar desde caché
        SELECT
            datos_insert_call,
            datos_resultado_perfil,
            costos
        FROM cache_detalle_leads
        WHERE F9CallID = p_f9callid;

        -- Incrementar contador de acceso
        UPDATE cache_detalle_leads
        SET veces_accedido = veces_accedido + 1
        WHERE F9CallID = p_f9callid;
    ELSE
        -- Buscar en base de datos
        -- (Aquí iría la query optimizada)
        SELECT
            JSON_OBJECT(
                'clave', sld.clave,
                'valor', sld.valor
            )) as datos_insert_call,
            -- ... resto de la query
        FROM siigo_lead_data_v2 sld
        WHERE sld.F9CallID = p_f9callid
            AND sld.method = 'Insert Call';

        -- Guardar en caché para futuras consultas
        INSERT INTO cache_detalle_leads (F9CallID, datos_insert_call, datos_resultado_perfil, costos)
        VALUES (p_f9callid, v_datos_json, ..., ...)
        ON DUPLICATE KEY UPDATE
            datos_insert_call = VALUES(datos_insert_call),
            datos_resultado_perfil = VALUES(datos_resultado_perfil),
            costos = VALUES(costos),
            ultima_actualizacion = NOW();
    END IF;
END //
DELIMITER ;

-- ============================================
-- 7. LIMPIEZA DE CACHÉ
-- ============================================

-- Procedimiento para limpiar caché antigua
CREATE PROCEDURE IF NOT EXISTS limpiar_cache_detalle_leads()
BEGIN
    -- Eliminar entradas no accedidas en 7 días
    DELETE FROM cache_detalle_leads
    WHERE ultima_actualizacion < DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND veces_accedido < 5;

    -- Eliminar entradas muy viejas (30 días)
    DELETE FROM cache_detalle_leads
    WHERE ultima_actualizacion < DATE_SUB(NOW(), INTERVAL 30 DAY);

    SELECT CONCAT('Caché limpiada. Entradas restantes: ', COUNT(*)) as mensaje
    FROM cache_detalle_leads;
END;

-- ============================================
-- 8. MONITOREO DE RENDIMIENTO
-- ============================================

-- Crear tabla para monitorear tiempos de consulta
CREATE TABLE IF NOT EXISTS monitor_rendimiento_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    F9CallID VARCHAR(100),
    query_tiempo_segundos DECIMAL(5,3),
    num_queries INT,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_f9callid (F9CallID),
    INDEX idx_fecha (fecha)
);

-- ============================================
-- 9. QUERIES DE ANÁLISIS
-- ============================================

-- Query para identificar F9CallIDs más lentos
-- SELECT F9CallID, AVG(query_tiempo_segundos) as promedio_tiempo
-- FROM monitor_rendimiento_detalle
-- GROUP BY F9CallID
-- ORDER BY promedio_tiempo DESC
-- LIMIT 20;

-- Query para ver tendencias de rendimiento
-- SELECT DATE(fecha) as dia, AVG(query_tiempo_segundos) as promedio_tiempo
-- FROM monitor_rendimiento_detalle
-- WHERE fecha > DATE_SUB(NOW(), INTERVAL 7 DAY)
-- GROUP BY DATE(fecha)
-- ORDER BY dia;

-- ============================================
-- FIN
-- ============================================

-- Para ejecutar:
-- 1. Crear los índices primero
-- 2. Usar el archivo detalle_lead_v2_optimizado.php
-- 3. Monitorear el rendimiento con la tabla monitor_rendimiento_detalle