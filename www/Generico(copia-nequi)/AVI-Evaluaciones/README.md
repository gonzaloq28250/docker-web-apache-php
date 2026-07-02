# Dashboard de Llamadas

Sistema de dashboard para gestión y análisis de llamadas.

## Configuración Multi-Cliente

Este dashboard está diseñado para ser reutilizable con diferentes clientes. Para cambiar de cliente, simplemente edita el archivo `config.php`.

### Cómo cambiar de cliente

1. Abre el archivo `config.php` en el directorio raíz del dashboard
2. Modifica las siguientes constantes:

```php
// Cambiar el nombre del cliente
define('CLIENTE_ACTUAL', 'NOMBRE_DEL_CLIENTE');

// Cambiar el título del dashboard (opcional)
define('DASHBOARD_TITLE', 'Dashboard de Llamadas - NOMBRE_DEL_CLIENTE');
```

3. Guarda el archivo
4. Todas las páginas del dashboard automáticamente usarán el nuevo cliente

### Ejemplo

Para cambiar de NEQUI a SIIGO:

```php
// Antes
define('CLIENTE_ACTUAL', 'NEQUI');
define('DASHBOARD_TITLE', 'Dashboard de Llamadas - NEQUI');

// Después
define('CLIENTE_ACTUAL', 'SIIGO');
define('DASHBOARD_TITLE', 'Dashboard de Llamadas - SIIGO');
```

### Archivos que usan la configuración

Los siguientes archivos se actualizaron para usar el sistema de configuración centralizado:

- `config.php` - Archivo de configuración principal
- `dashboard.php` - Dashboard en tiempo real
- `dashboard_data.php` - API para auto-refresh del dashboard
- `get_llamadas_by_resultado.php` - API para obtener llamadas por resultado
- `get_llamadas_by_resultado_filtrado.php` - API para obtener llamadas filtradas

### Configuración de Base de Datos

Las credenciales de base de datos también están centralizadas en `config.php`:

```php
define('DB_HOST', 'icqdbmysqlreports.mysql.database.azure.com');
define('DB_NAME', 'n8n_icq');
define('DB_USER', 'gonzaloq');
define('DB_PASS', '73ch$iCC');
```

Si necesitas cambiar la conexión a base de datos, edita estas constantes.

### Seguridad

**IMPORTANTE:** El archivo `config.php` contiene credenciales sensibles. Asegúrate de:
- No subirlo a repositorios públicos
- Protegerlo con permisos adecuados en el servidor
- Considerar usar variables de entorno en producción

## Estructura de Archivos

- `config.php` - Configuración central
- `dashboard.php` - Dashboard principal con auto-refresh
- `nequi_dashboard.php` - Dashboard de costos y análisis
- `leads_dinamicos_v2.php` - Lista de leads y resumen estadístico
- `leads_dinamicos_breakdown.php` - Desglose detallado de leads
- `costos_dashboard.php` - Dashboard de análisis de costos
- `detalle_lead_v2.php` - Vista detallada de un lead individual
- `CLAUDE.md` - Documentación arquitectónica para desarrollo
