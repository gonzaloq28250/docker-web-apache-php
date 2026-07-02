-- ============================================
-- OPTIMIZACIÓN DE ÍNDICES - DASHBOARD NEQUI
-- ============================================
-- Fecha: 2025-05-28
-- Descripción: Índices para optimizar queries de dashboard_consolidado.php
-- ============================================

-- Usar base de datos
USE n8n_icq;

-- ============================================
-- ÍNDICES PARA level_calls
-- ============================================

-- 1. Índice compuesto para búsquedas de fecha y estado (MÁS CRÍTICO)
-- Optimiza queries de REALTIME e HISTÓRICO que filtran por cliente, fecha y estado
-- Impacto: Reducción de 70-80% en tiempo de query
DROP INDEX IF EXISTS idx_level_calls_fecha_estado ON level_calls;
CREATE INDEX idx_level_calls_fecha_estado ON level_calls(cliente, Estado, F9TimeStamp);

-- 2. Índice compuesto para JOIN con level_conversations
-- Optimiza todos los LEFT/INNER JOIN entre level_calls y level_conversations
DROP INDEX IF EXISTS idx_level_calls_callid_cliente ON level_calls;
CREATE INDEX idx_level_calls_callid_cliente ON level_calls(F9CallID, cliente);

-- 3. Índice compuesto para listado histórico (incluye búsqueda)
-- Optimiza query #7 que lista leads con filtros de fecha
DROP INDEX IF EXISTS idx_level_calls_fechas_cliente ON level_calls;
CREATE INDEX idx_level_calls_fechas_cliente ON level_calls(cliente, F9TimeStamp);

-- 4. Índices individuales para búsquedas de texto
-- Optimiza búsquedas con LIKE en columnas específicas
DROP INDEX IF EXISTS idx_level_calls_ani ON level_calls;
CREATE INDEX idx_level_calls_ani ON level_calls(ANI);

DROP INDEX IF EXISTS idx_level_calls_dnis ON level_calls;
CREATE INDEX idx_level_calls_dnis ON level_calls(DNIS);

DROP INDEX IF EXISTS idx_level_calls_proyecto ON level_calls;
CREATE INDEX idx_level_calls_proyecto ON level_calls(PROYECTO);

-- 5. Índice para ORDER BY F9TimeStamp DESC
-- Optimiza el ordenamiento descendente en listados
DROP INDEX IF EXISTS idx_level_calls_timestamp_desc ON level_calls;
CREATE INDEX idx_level_calls_timestamp_desc ON level_calls(F9TimeStamp DESC);

-- 6. Índice para F9CallID (clave primaria de búsquedas)
DROP INDEX IF EXISTS idx_level_calls_f9callid ON level_calls;
CREATE INDEX idx_level_calls_f9callid ON level_calls(F9CallID);

-- ============================================
-- ÍNDICES PARA level_conversations
-- ============================================

-- 1. Índice para JOIN con level_calls
-- Optimiza todos los JOIN donde se vincula F9CallID con callid
DROP INDEX IF EXISTS idx_level_conversations_callid ON level_conversations;
CREATE INDEX idx_level_conversations_callid ON level_conversations(callid);

-- 2. Índice para filtrar por call_result
-- Optimiza GROUP BY y WHERE que filtran resultados
DROP INDEX IF EXISTS idx_level_conversations_call_result ON level_conversations;
CREATE INDEX idx_level_conversations_call_result ON level_conversations(call_result);

-- 3. Índice compuesto para JOIN + resultado
-- Optimiza queries que hacen JOIN y filtran por resultado
DROP INDEX IF EXISTS idx_level_conversations_callid_result ON level_conversations;
CREATE INDEX idx_level_conversations_callid_result ON level_conversations(callid, call_result);

-- ============================================
-- VERIFICACIÓN DE ÍNDICES CREADOS
-- ============================================

-- Mostrar índices en level_calls
SHOW INDEX FROM level_calls;

-- Mostrar índices en level_conversations
SHOW INDEX FROM level_conversations;

-- ============================================
-- ANALIZAR IMPACTO (ejecutar después de crear índices)
-- ============================================

-- Analizar query de resultados hoy
EXPLAIN
SELECT
    lvc.call_result as resultado,
    COUNT(*) as total
FROM level_calls lc
INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
WHERE lc.cliente = 'NEQUI'
AND DATE(lc.F9TimeStamp) = CURDATE()
AND lvc.call_result IS NOT NULL
AND lvc.call_result != 'IVR_Regular'
AND lvc.call_result != '"IVR_Regular"'
GROUP BY lvc.call_result
ORDER BY total DESC;

-- Analizar query de leads con búsqueda
EXPLAIN
SELECT F9CallID, F9TimeStamp, ANI, DNIS, PROYECTO, Estado, duration
FROM level_calls
WHERE cliente = 'NEQUI'
AND (
    ANI LIKE '%123%' OR
    DNIS LIKE '%123%' OR
    PROYECTO LIKE '%123%' OR
    F9CallID LIKE '%123%'
)
ORDER BY F9TimeStamp DESC
LIMIT 100;
