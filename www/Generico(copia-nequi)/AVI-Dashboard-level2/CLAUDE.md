# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

NEQUI Dashboard is a PHP-based call tracking and lead management system that displays call analytics, transcriptions, and detailed lead information for multiple clients. The application supports multi-client configuration and provides multiple views for monitoring call campaigns.

## Multi-Client Configuration

The dashboard supports multiple clients through a centralized configuration system. To switch between clients:

1. Edit `config.php` (lines 10-14) to set `CLIENTE_ACTUAL` constant
2. All dashboard pages automatically use the configured client
3. The same codebase can serve NEQUI, ICQ24-GQA, or any other client

Current clients in codebase: NEQUI (default), ICQ24-GQA

## Database Connections

### Primary Database (Azure MySQL)
- Host: `icqdbmysqlreports.mysql.database.azure.com`
- Database: `n8n_icq`
- Used by: All main dashboard files
- Connection via `getDBConnection()` from `config.php`

**Important:** Database credentials are centralized in `config.php` (lines 17-20). When modifying database-related code, always `require_once 'config.php'` and use `getDBConnection()` instead of creating new PDO connections.

### Secondary Database (FreePBX/MariaDB)
- Host: `phone.icq24.com:3306`
- Database: `asteriskcdrdb`
- Used by: `reporte-freepbx.php`, `get_cel_detalle.php`
- Connection: Direct PDO with hardcoded credentials (lines 6-21 in both files)
- Purpose: Detailed call event logging from Asterisk/FreePBX

## Key Database Tables

- **siigo_lead_data_v2**: Core table storing lead data in key-value pairs indexed by `F9CallID`. Uses `method` field to distinguish data types ('Insert Call', 'ResultadoPerfil'). Always filter by `CLIENTE = 'NEQUI'` when querying for this client.
- **avi_calls**: Tracks call states and metadata. Contains `Estado` field for call progress tracking. Used for counting total calls realized.
- **avi_call_costs**: Cost tracking table with call duration, costs (call_cost_usd, llm_cost_total_usd), and metadata. Used by cost dashboards.
- **eleven_n8n_t1**: Stores conversation transcripts indexed by `ElevenConversationID` and `from_number` (corresponds to F911DNIS).
- **eleven_n8n_t1_analisis**: Contains AI analysis results with `criteria_id`, `result` (success/unknown/failure), and `rationale` fields.

## Application Architecture

### Main Dashboard Pages

1. **config.php** - Centralized configuration file
   - Defines CLIENTE_ACTUAL constant for multi-client support
   - Centralizes database credentials
   - Provides `getDBConnection()` helper function
   - Include this at the top of all dashboard pages: `require_once 'config.php'`

2. **leads_dinamicos_v2.php** - Main leads listing page
   - Displays filterable table of all NEQUI leads
   - Filters: Date range (fecha_desde/fecha_hasta), Project (PROYECTO), ANI
   - Uses dynamic column generation based on unique keys in database
   - Shows daily summary table with breakdown by call result
   - Includes "Llamadas Realizadas", result columns, and "Llamadas Colgadas"
   - Excel export functionality using SheetJS
   - Filter parameters are preserved in query string when navigating to detail view
   - Query pattern: Uses EXISTS subqueries to filter by key-value pairs from siigo_lead_data_v2

3. **leads_dinamicos_breakdown.php** - Enhanced leads page with classification breakdown
   - Copy of leads_dinamicos_v2.php with additional breakdown section
   - Shows visual breakdown of call classifications with progress bars
   - Color-coded by result type (green=success, yellow=no answer, red=failure)
   - Each category has "Ver Llamadas" button that opens modal with filtered calls
   - Modal allows drilling down to individual call details
   - Uses `get_llamadas_by_resultado_filtrado.php` API endpoint

4. **detalle_lead_v2.php** - Detailed lead view
   - Shows comprehensive information for a single F9CallID
   - Three data sections:
     - "Datos de Llamada" from `method = 'Insert Call'`
     - "AVI Result" from `method = 'ResultadoPerfil'`
     - Transcriptions from eleven_n8n_t1 (matched by F911DNIS)
     - Analysis results from eleven_n8n_t1_analisis
   - Displays success/unknown/failure statistics with percentage calculations
   - Maintains filter state via back link

5. **dashboard.php** - Real-time call monitoring dashboard
   - Auto-refreshes at configurable intervals (2s to 60s)
   - Shows IN-PROGRESS calls from avi_calls table
   - Client-side pagination (100 records, 10 per page) for recent calls
   - Shows breakdown of call results with visual progress bars
   - Includes "Llamadas colgadas" (hung up calls) calculated as difference between total calls and calls with results
   - "Ver Llamadas" buttons open modal with filtered calls by result type
   - Nested modals: resultado list → call detail

6. **dashboard_data.php** - JSON API endpoint
   - Powers dashboard.php auto-refresh functionality
   - Returns JSON with HTML snippets and data arrays
   - Accepts `cliente` parameter (defaults to 'NEQUI')
   - Returns: inProgressHTML, otrosData (array), resultadoHTML, totalRealizadasHTML

### Analytics & Cost Dashboards

7. **dashboard_consolidado.php** - Consolidated dashboard with realtime + historical views
   - Two-section layout: REALTIME (today's data) and HISTÓRICO (filtered historical data)
   - REALTIME section: Auto-refreshing KPIs, pie chart of call types
   - HISTÓRICO section: Date/time range filter with search functionality
   - Excel export for daily summary and interaction details
   - Realtime refresh via `dashboard_realtime_data.php` API
   - Refresh persistence using localStorage (survives page reload within 5 min)
   - KPIs: Llamadas en Curso, Five9 total, AVI total, duration, LLM costs, retention rate
   - Includes daily summary table with percentage calculations by result type

8. **customer_costos.php** - Customer-specific call analysis dashboard
   - Blue gradient theme (from-blue-600 to-blue-800)
   - 3 KPIs: Total calls, duration total (minutes), duration average (MM:SS)
   - Date range filter only
   - Chart.js line chart showing daily call volume
   - Compares Five9 vs AVI call volumes side-by-side
   - Two tables: daily summary with both sources, top 10 longest calls

9. **dashboard_realtime_data.php** - JSON API endpoint for consolidated dashboard
   - Returns today's realtime data without page refresh
   - Includes: llamadasEnCurso, totalLlamadasFive9, totalLlamadasAVI, duration metrics, LLM costs, retention rate, call type breakdown
   - Used by dashboard_consolidado.php auto-refresh functionality
   - Timezone: America/Puerto_Rico

10. **reporte-freepbx.php** - FreePBX call event reporting (separate system)
   - Connects to asteriskcdrdb (different database)
   - Shows detailed call event flow (CHAN_START, HANGUP, ANSWER, BRIDGE_ENTER/EXIT)
   - Identifies who hung up (Caller, Agent/Peer, PBX, or trunk name)
   - Filters: date range, phone number, extension, context, who-hung-up
   - KPIs: Total calls, total duration, average duration, total billsec
   - Pie charts: hangup distribution, disposition distribution
   - Modal for viewing full CEL event log via `get_cel_detalle.php`
   - Excel export capability

### Analytics & Cost Dashboards (continued)

### Analytics & Cost Dashboards (continued)

11. **costos_dashboard.php** - Comprehensive cost analytics dashboard
   - Standalone page for analyzing call and LLM costs
   - 8 KPIs: Total calls, call costs, LLM costs, total cost, duration metrics, cost per minute, efficiency ratio
   - Date range filter (fecha_inicio/fecha_fin) and client filter
   - Chart.js visualizations: line chart (daily trend), doughnut chart (cost distribution)
   - Three tables: daily summary with totals, breakdown by client, top 10 costly calls
   - Duration displayed in MM:SS format using helper function `secondsToMMSS()`
   - Queries `avi_call_costs` table

12. **nequi_dashboard.php** - NEQUI-specific call analytics (no cost data)
   - Client fixed to 'NEQUI', removed from filters
   - 3 KPIs: Total calls, duration total (minutes), duration average (MM:SS)
   - Date range filter only
   - Chart.js line chart showing daily call volume
   - Two tables: daily summary, top 10 longest calls
   - Blue color scheme to differentiate from cost dashboard

### API Endpoints

13. **get_llamadas_by_resultado.php** - Fetch calls by result (today only)
   - Returns JSON with llamadas array for a specific resultado
   - Client fixed to 'NEQUI', date fixed to CURDATE()
   - Handles special case: "Llamadas colgadas" (calls without resultado_llamada)
   - Used by dashboard.php

14. **get_llamadas_by_resultado_filtrado.php** - Fetch calls by result (with filters)
   - Enhanced version supporting date range, project, and ANI filters
   - Accepts: resultado, fecha_desde, fecha_hasta, proyecto, ani
   - Applies same filter logic as main pages
   - Used by leads_dinamicos_breakdown.php

15. **get_cel_detalle.php** - JSON API for FreePBX CEL event details
   - Returns full CEL event log for a given linkedid
   - Connects to asteriskcdrdb database
   - Returns: success, data (array of CEL events) or error
   - Used by reporte-freepbx.php modal

## Auto-Refresh Pattern with Persistence

**dashboard_consolidado.php** implements an auto-refresh system with localStorage persistence:

### How it works:
1. User selects refresh interval (5s, 10s, 15s, 30s, 60s) and clicks "Iniciar"
2. Config saved to localStorage with timestamp: `{interval, isActive, savedAt}`
3. JavaScript `setInterval` calls `dashboard_realtime_data.php` API every N seconds
4. UI updates without page reload (KPI numbers, pie chart, result table)
5. Refresh indicator flashes briefly on each update

### Persistence behavior:
- Config survives page reload (stored in localStorage)
- Config expires after 5 minutes of inactivity
- On page load: restores config if valid and under 5 min old, auto-starts refresh
- "Detener" button clears localStorage config

### Storage keys:
- `dashboard_refresh_config`: JSON object with interval/isActive/savedAt
- `dashboard_refresh_timestamp`: Unix timestamp of last save

## Data Flow Patterns

### Key-Value Data Structure
The siigo_lead_data_v2 table stores data as key-value pairs per F9CallID. To retrieve structured data:
1. Query with WHERE clause filtering by `method` and optional EXISTS subqueries for specific keys
2. Organize results by F9CallID in an associative array
3. Access values like: `$data[$f9CallID][$key]`

### Linking Data Across Tables
- F9CallID links avi_calls and siigo_lead_data_v2
- F911DNIS (from siigo_lead_data_v2) links to from_number in eleven_n8n_t1
- ElevenConversationID links eleven_n8n_t1 and eleven_n8n_t1_analisis

### Filter Preservation
When implementing new filtered views:
1. Build $filterParams array from $_GET
2. Append to links using: `http_build_query($filterParams)`
3. Construct WHERE clause using EXISTS subqueries for each filter

## UI/Styling & JavaScript Libraries

- **Tailwind CSS** via CDN (https://cdn.tailwindcss.com) - All styling
- **Font Awesome 6** for icons (https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css)
- **Chart.js** for data visualizations (line charts, doughnut charts) in cost dashboards
- **SheetJS (XLSX)** for Excel export functionality (https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js)
- Color scheme:
  - Primary: Blue gradient (from-blue-600 to-blue-800)
  - Cost dashboards: Purple gradient (from-purple-600 to-purple-800)
  - Success: Green (#10b981, bg-green-500)
  - Unknown: Yellow (#f59e0b, bg-yellow-500)
  - Failure: Red (#ef4444, bg-red-500)
- Responsive design with mobile-first approach using Tailwind breakpoints (md:, lg:)

## Common Query Patterns

### Filter by NEQUI client:
```sql
WHERE EXISTS (
    SELECT 1 FROM siigo_lead_data_v2 c
    WHERE c.F9CallID = d.F9CallID
    AND c.clave = 'CLIENTE'
    AND c.valor = 'NEQUI'
)
```

### Filter by date range:
```sql
-- Date range (both specified)
WHERE EXISTS (
    SELECT 1 FROM siigo_lead_data_v2 f
    WHERE f.F9CallID = d.F9CallID
    AND f.clave = 'F9TimeStamp'
    AND DATE(f.valor) BETWEEN :fecha_desde AND :fecha_hasta
)

-- Single date (desde only)
WHERE DATE(f.valor) >= :fecha_desde

-- Single date (hasta only)
WHERE DATE(f.valor) <= :fecha_hasta
```

### Get all key-value pairs for a lead:
```php
$sql = "SELECT clave, valor FROM siigo_lead_data_v2
        WHERE F9CallID = :f9id AND method = 'ResultadoPerfil'";
```

## Development Notes

- No build process required - pure PHP with CDN assets
- No package.json or dependency management
- Testing requires a live PHP environment with database access
- All styling is inline using Tailwind utility classes
- JavaScript is minimal and embedded in PHP files (toggle functions, auto-refresh, clipboard copy)

## Modal Patterns

The application uses nested modal patterns for drill-down navigation:

### Two-Modal Pattern (leads_dinamicos_breakdown.php, dashboard.php)
1. **First Modal** (`resultadoModal`): Shows table of calls filtered by result type
   - Opened by clicking "Ver Llamadas" button on breakdown category
   - Fetches data via API endpoint with filters applied
   - z-index: 40
2. **Second Modal** (`detailModal`): Shows detailed lead information in iframe
   - Opened by clicking "Ver Detalle" in the first modal
   - Loads detalle_lead_v2.php in iframe
   - z-index: 50 (higher to appear above first modal)

### Modal Implementation
```javascript
// Open modal
function openResultadoModal(resultado) {
    document.getElementById('resultadoModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    loadResultadoLlamadas(resultado); // Fetch via API
}

// Close modal
function closeResultadoModal() {
    document.getElementById('resultadoModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}
```

### Helper Functions

**secondsToMMSS()** - Convert seconds to MM:SS format
```php
function secondsToMMSS($seconds) {
    if ($seconds <= 0) return '00:00';
    $totalSeconds = (int)round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d', $minutes, $secs);
}
```

**secondsToHHMMSS()** - Convert seconds to HH:MM:SS format
```php
function secondsToHHMMSS($seconds) {
    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $secs = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}
```

## Working with This Codebase

### Adding a new dashboard page:

1. Start with `require_once 'config.php'` at the top
2. Use `$pdo = getDBConnection()` for database access
3. Use `$clienteActual = CLIENTE_ACTUAL` for client filtering
4. Follow existing Tailwind gradient patterns for headers:
   - Main dashboards: `from-blue-600 to-blue-800`
   - Cost dashboards: `from-purple-600 to-purple-800`
   - Consolidated: `from-indigo-600 to-purple-700`

### Adding auto-refresh to a page:

1. Create an API endpoint (e.g., `your_page_data.php`) that returns JSON
2. Add refresh controls: interval select, toggle button, status text
3. Implement `toggleAutoRefresh()`, `startAutoRefresh()`, `stopAutoRefresh()` functions
4. Use localStorage keys specific to your page (e.g., `yourpage_refresh_config`)
5. On page load, restore config if under 5 minutes old

When adding new features:
1. Use prepared statements for all user input
2. Follow the EXISTS subquery pattern for filtering siigo_lead_data_v2
3. Preserve filter parameters when creating navigation links using URLSearchParams or http_build_query
4. Use htmlspecialchars() for all output to prevent XSS
5. Use escapeHtml() JavaScript function for dynamic HTML content
6. Follow existing Tailwind utility class patterns for styling
7. Keep responsive design in mind (mobile, tablet, desktop)
8. For modals, maintain z-index hierarchy (first modal: z-40, second modal: z-50)
9. When creating API endpoints that accept filters, support: fecha_desde, fecha_hasta, proyecto, ani
10. Use Chart.js for new visualizations (already loaded in cost dashboards)

### Database patterns:

- Main database queries filter by `cliente = :cliente` constant
- Timezone: America/Puerto_Rico (set in dashboard_consolidado.php)
- Five9 data: `avi_calls` table (F9TimeStamp, Estado fields)
- AVI data: `avi_call_costs` table (metadata_date_local, connection_duration_secs, llm_cost_total_usd)
- Lead data: `siigo_lead_data_v2` table (key-value structure)
- FreePBX data: `cel` and `cdr` tables in asteriskcdrdb
