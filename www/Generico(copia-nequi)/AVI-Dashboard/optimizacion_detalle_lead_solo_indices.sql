-- ============================================
-- ÍNDICES PARA detalle_lead_v2.php
-- ============================================

-- Solo índices que funcionan en MySQL (sin WHERE clauses)

-- ============================================
-- 1. ÍNDICES PARA siigo_lead_data_v2 (CRÍTICOS)
-- ============================================

-- Índice principal para búsqueda por F9CallID y método
CREATE INDEX idx_siigo_f9callid_method ON siigo_lead_data_v2(F9CallID, method);

-- Índice para F9CallID específico
CREATE INDEX idx_siogo_f9callid_specific ON siigo_lead_data_v2(F9CallID, clave, method);

-- ============================================
-- 2. ÍNDICES PARA avi_call_costs (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por f9_call_id
CREATE INDEX idx_avicosts_f9id ON avi_call_costs(f9_call_id);

-- Índice compuesto para duración y costo
CREATE INDEX idx_avicosts_f9id_duracion_costo ON avi_call_costs(f9_call_id, connection_duration_secs, llm_cost_total_usd);

-- ============================================
-- 3. ÍNDICES PARA eleven_n8n_t1 (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por from_number (DNIS)
CREATE INDEX idx_eleven_from_number ON eleven_n8n_t1(from_number);

-- Índice para ElevenConversationID
CREATE INDEX idx_eleven_conversation_id ON eleven_n8n_t1(ElevenConversationID);

-- ============================================
-- 4. ÍNDICES PARA eleven_n8n_t1_analisis (CRÍTICOS)
-- ============================================

-- Índice para búsqueda por ElevenConversationID
CREATE INDEX idx_eleven_analisis_conversation ON eleven_n8n_t1_analisis(ElevenConversationID);

-- ============================================
-- VERIFICACIÓN
-- ============================================

-- Verificar que los índices se crearon correctamente
-- SHOW INDEX FROM siigo_lead_data_v2;
-- SHOW INDEX FROM avi_call_costs;
-- SHOW INDEX FROM eleven_n8n_t1;
-- SHOW INDEX FROM eleven_n8n_t1_analisis;

-- ============================================
-- FIN
-- ============================================