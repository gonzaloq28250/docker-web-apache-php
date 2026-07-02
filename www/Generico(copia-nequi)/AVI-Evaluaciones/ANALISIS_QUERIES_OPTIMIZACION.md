# Análisis de Queries y Optimización - Dashboard NEQUI

## 📊 Queries Identificados

### 1. dashboard_consolidado.php (8 queries principales)

#### REALTIME (Diario)
| # | Query | Tablas | Tipo | Descripción |
|---|-------|--------|------|-------------|
| 1 | `COUNT(*) WHERE Estado='IN-PROGRESS'` | `level_calls` | COUNT | Llamadas en curso hoy |
| 2 | `SELECT call_result, COUNT(*)` LEFT JOIN | `level_calls` + `level_conversations` | GROUP BY | Resultados de llamadas hoy |
| 3 | `SELECT SUM(duration)` LEFT JOIN | `level_calls` + `level_conversations` | SUM | Duración total hoy |

#### HISTÓRICO (Con filtros)
| # | Query | Tablas | Tipo | Descripción |
|---|-------|--------|------|-------------|
| 4 | `SELECT call_result, COUNT(*)` LEFT JOIN | `level_calls` + `level_conversations` | GROUP BY | Resultados filtrados por fecha |
| 5 | `SELECT SUM(duration)` LEFT JOIN | `level_calls` + `level_conversations` | SUM | Duración total filtrada |
| 6 | `SELECT DATE(F9TimeStamp), call_result, COUNT(*)` LEFT JOIN | `level_calls` + `level_conversations` | GROUP BY | Resumen diario por fecha/resultado |
| 7 | `SELECT F9CallID, F9TimeStamp, ANI, DNIS...` | `level_calls` | SELECT + LIKE | Leads históricos con búsqueda |
| 8 | `SELECT F9CallID, call_result` LEFT JOIN | `level_calls` + `level_conversations` | SELECT | Resultados de leads |

### 2. dashboard_realtime_data.php (3 queries - API JSON)

| # | Query | Tablas | Tipo | Descripción |
|---|-------|--------|------|-------------|
| 1 | `COUNT(*) WHERE Estado='IN-PROGRESS'` | `level_calls` | COUNT | Llamadas en curso hoy |
| 2 | `SELECT SUM(duration)` LEFT JOIN | `level_calls` + `level_conversations` | SUM | Duración total hoy |
| 3 | `SELECT call_result, COUNT(*)` LEFT JOIN | `level_calls` + `level_conversations` | GROUP BY | Resultados de llamadas hoy |

### 3. detalle_lead_v2.php (5 queries - Detalle individual)

| # | Query | Tablas | Tipo | Descripción |
|---|-------|--------|------|-------------|
| 1 | `SELECT clave, valor FROM siigo_lead_data_v2` | `siigo_lead_data_v2` | SELECT | Datos de Insert Call por F9CallID |
| 2 | `SELECT clave, valor FROM siigo_lead_data_v2` | `siigo_lead_data_v2` | SELECT | Datos de ResultadoPerfil por F9CallID |
| 3 | `SELECT DNIS, duration, ANI FROM level_calls` | `level_calls` | SELECT | Datos básicos de llamada por F9CallID |
| 4 | `SELECT * FROM level_conversations` | `level_conversations` | SELECT | Transcripción y resultado por callid |

---

## 🚨 Problemas de Rendimiento Identificados

### 1. Queries Repetitivos
- **Problema**: Queries 2 y 3 hacen el mismo LEFT JOIN en varias ocasiones
- **Impacto**: Múltiples escaneos de las mismas tablas

### 2. Búsqueda con LIKE sin índices
- **Problema**: Query #7 usa `LIKE %texto%` en 4 columnas diferentes
- **Código**: `WHERE ANI LIKE :buscar OR DNIS LIKE :buscar OR PROYECTO LIKE :buscar OR F9CallID LIKE :buscar`
- **Impacto**: Búsqueda lenta sin índices adecuados

### 3. DATE() en WHERE clause
- **Problema**: `DATE(F9TimeStamp)` convierte cada fila, impidiendo uso de índices
- **Impacto**: Escaneo completo de tabla

### 4. LEFT JOIN que podría ser INNER JOIN
- **Problema**: Muchos queries usan LEFT JOIN pero luego filtran `WHERE lvc.call_result IS NOT NULL`
- **Impacto**: El optimizador no puede usar ciertas optimizaciones

---

## 🔧 Recomendaciones de Optimización

### A. Índices Recomendados para `level_calls`

```sql
-- Índice compuesto para búsquedas de fecha y estado (más crítico)
CREATE INDEX idx_level_calls_fecha_estado ON level_calls(cliente, DATE(F9TimeStamp), Estado);

-- Índice compuesto para joins con level_conversations
CREATE INDEX idx_level_calls_callid_cliente ON level_calls(F9CallID, cliente);

-- Índice compuesto para listado histórico (incluye búsqueda)
CREATE INDEX idx_level_calls_busqueda ON level_calls(cliente, F9CallID, ANI, DNIS, PROYECTO);

-- Índice para filtros de rango de fechas
CREATE INDEX idx_level_calls_fechas ON level_calls(cliente, F9TimeStamp);

-- Índice para búsquedas de texto
CREATE INDEX idx_level_calls_ani ON level_calls(ANI);
CREATE INDEX idx_level_calls_dnis ON level_calls(DNIS);
CREATE INDEX idx_level_calls_proyecto ON level_calls(PROYECTO);
```

### B. Índices Recomendados para `level_conversations`

```sql
-- Índice para JOIN con level_calls
CREATE INDEX idx_level_conversations_callid ON level_conversations(callid);

-- Índice para filtrar por call_result
CREATE INDEX idx_level_conversations_call_result ON level_conversations(call_result, callid);

-- Índice compuesto para excluir IVR_Regular
CREATE INDEX idx_level_conversations_resultado ON level_conversations(call_result) WHERE call_result NOT IN ('IVR_Regular', '"IVR_Regular"');
```

### C. Optimización de Queries Específicos

#### 1. Cambiar DATE() a rango de fechas
**ANTES:**
```sql
WHERE DATE(F9TimeStamp) = :fecha_hoy
```

**DESPUÉS:**
```sql
WHERE F9TimeStamp >= :fecha_inicio 
  AND F9TimeStamp < :fecha_fin
```

#### 2. Usar INNER JOIN en lugar de LEFT JOIN
**ANTES:**
```sql
FROM level_calls lc
LEFT JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
WHERE lvc.call_result IS NOT NULL
```

**DESPUÉS:**
```sql
FROM level_calls lc
INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
WHERE lvc.call_result IS NOT NULL
  AND lvc.call_result NOT IN ('IVR_Regular', '"IVR_Regular"')
```

#### 3. Optimizar búsqueda con LIKE
**ANTES:**
```sql
WHERE ANI LIKE :buscar OR DNIS LIKE :buscar OR PROYECTO LIKE :buscar OR F9CallID LIKE :buscar
```

**DESPUÉS (usar FULLTEXT):**
```sql
-- Agregar índice FULLTEXT
ALTER TABLE level_calls ADD FULLTEXT INDEX ft_busqueda (ANI, DNIS, PROYECTO, F9CallID);

-- Usar MATCH AGAINST
WHERE MATCH(ANI, DNIS, PROYECTO, F9CallID) AGAINST(:buscar IN BOOLEAN MODE)
```

### D. Uso de VIEW Materializada para Queries Complejos

```sql
-- Crear VIEW materializada para resumen diario
CREATE TABLE mv_resumen_diario AS
SELECT 
    DATE(lc.F9TimeStamp) as fecha,
    lc.cliente,
    lvc.call_result as resultado,
    COUNT(*) as total,
    SUM(lc.duration) as duracion_total
FROM level_calls lc
INNER JOIN level_conversations lvc ON lc.F9CallID = lvc.callid
WHERE lvc.call_result IS NOT NULL
  AND lvc.call_result NOT IN ('IVR_Regular', '"IVR_Regular"')
GROUP BY DATE(lc.F9TimeStamp), lc.cliente, lvc.call_result;

-- Crear índices en la VIEW materializada
CREATE INDEX idx_mv_resumen_fecha_cliente ON mv_resumen_diario(fecha, cliente);
CREATE INDEX idx_mv_resumen_resultado ON mv_resumen_diario(resultado);

-- Actualizar periódicamente (cron job cada hora)
-- REPLACE INTO mv_resumen_diario SELECT ...
```

---

## 📈 Impacto Estimado de Optimizaciones

| Optimización | Reducción Tiempo Query | Impacto |
|--------------|------------------------|---------|
| Índices compuestos (fecha+estado) | 70-80% | 🔴 ALTO |
| Eliminar DATE() en WHERE | 40-50% | 🔴 ALTO |
| INNER JOIN vs LEFT JOIN | 10-15% | 🟡 MEDIO |
| FULLTEXT vs LIKE | 60-70% | 🟠 MEDIO-ALTO |
| VIEW materializada | 80-90% | 🟢 MUY ALTO |

---

## 🎯 Plan de Implementación (Priorizado)

### FASE 1: Crítico (Implementar inmediatamente)
1. ✅ Crear índices compuestos de fecha y estado
2. ✅ Cambiar DATE() a rangos de fechas
3. ✅ Cambiar LEFT JOIN a INNER JOIN donde aplique

### FASE 2: Importante (Implementar esta semana)
4. ✅ Crear índices para búsquedas de texto (ANI, DNIS, etc.)
5. ✅ Optimizar búsqueda con LIKE

### FASE 3: Mejora continua (Implementar próxima semana)
6. ✅ Crear VIEW materializada para resumen diario
7. ✅ Configurar cron job para actualizar VIEW materializada

---

## 📊 Métricas de Monitoreo

### Queries a monitorear:
- Query #2 (Resultados hoy) - Objetivo: < 100ms
- Query #7 (Leads con búsqueda) - Objetivo: < 500ms
- Query #6 (Resumen diario) - Objetivo: < 200ms

### Herramientas recomendadas:
```sql
-- Habilitar slow query log (queries > 1s)
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;

-- Analizar execution plan
EXPLAIN SELECT ...;

-- Ver estadísticas de índices
SHOW INDEX FROM level_calls;
```

---

## 📝 Notas Importantes

1. **Cliente único**: Todos los queries filtran por `cliente`, lo cual es excelente para particionamiento
2. **Exclusión de IVR_Regular**: Este filtro está repetido en todas las queries - podría moverse a una vista o índice filtrado
3. **LEFT JOIN innecesario**: Casi todos los LEFT JOIN podrían ser INNER JOIN porque filtran por `call_result IS NOT NULL`
4. **Búsqueda actual**: Usa 4 LIKEs con wildcards, lo cual es muy ineficiente
