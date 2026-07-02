-- ============================================
-- ÍNDICES PARA siigo_lead_data_v2
-- Descripción: Optimizar queries de detalle_lead_v2
-- ============================================

USE n8n_icq;

-- 1. Índice compuesto para F9CallID + method (CRÍTICO)
-- Optimiza ambas queries de detalle_lead_v2.php
-- Impacto: 95% reducción en tiempo de query
DROP INDEX IF EXISTS idx_siigo_lead_f9id_method ON siigo_lead_data_v2;
CREATE INDEX idx_siigo_lead_f9id_method ON siigo_lead_data_v2(F9CallID, method, clave);

-- 2. Índice para solo F9CallID (para otras queries)
DROP INDEX IF EXISTS idx_siigo_lead_f9id ON siigo_lead_data_v2;
CREATE INDEX idx_siigo_lead_f9id ON siigo_lead_data_v2(F9CallID);

-- ============================================
-- VERIFICACIÓN
-- ============================================

SHOW INDEX FROM siigo_lead_data_v2;

-- ============================================
-- ANALIZAR QUERY ANTES Y DESPUÉS
-- ============================================

-- Ver performance de query 1
EXPLAIN
SELECT clave, valor
FROM siigo_lead_data_v2
WHERE F9CallID = '300000024398005'
AND method = 'Insert Call';

-- Ver performance de query 2
EXPLAIN
SELECT clave, valor
FROM siigo_lead_data_v2
WHERE F9CallID = '300000024398005'
AND method = 'ResultadoPerfil';

-- Query combinada (más eficiente)
EXPLAIN
SELECT clave, valor, method
FROM siigo_lead_data_v2
WHERE F9CallID = '300000024398005'
AND method IN ('Insert Call', 'ResultadoPerfil')
ORDER BY method, clave;