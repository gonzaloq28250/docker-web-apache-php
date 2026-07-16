# Docker Web Apache PHP

Contenedor Docker con Apache 2.4 + PHP 8.3 como reemplazo portable de Laragon para desarrollo local.

## Requisitos

- Docker Desktop (Windows) o Docker Engine (Linux)
- Git

## Uso rápido

```cmd
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git
cd docker-web-apache-php

REM (opcional) Usar carpeta www existente
echo WWW_PATH=C:/ruta/a/tu/www > .env

REM Iniciar
docker compose up -d

REM Probar
curl http://localhost/
```

## Configuración

### Variable WWW_PATH

Por defecto el document root apunta a `www/` (dentro del repo).
Para usar un directorio existente, crea un archivo `.env` en la raíz del repo:

```env
WWW_PATH=C:/ruta/a/tu/www
```

### Archivos config.php

Los archivos `config.php` con credenciales reales están excluidos del repo.
Usa `config.php.example` como plantilla y créalos localmente en cada
subdirectorio de `www/` que los necesite.

## Servicios

| Puerto | Servicio  |
|--------|-----------|
| 80     | HTTP      |
| 443    | HTTPS     |

## Troubleshooting

### `exec /usr/local/bin/entrypoint.sh: no such file or directory`

El archivo `entrypoint.sh` tiene saltos de línea Windows (CRLF) en lugar de
Unix (LF). Solución:

```cmd
REM Re-clonar (el .gitattributes ya corrige el problema)
rmdir /s /q docker-web-apache-php
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git
cd docker-web-apache-php
docker compose up -d
```

### Puerto 80 ocupado

Si otro programa (IIS, World Wide Web Publishing, Skype) usa el puerto 80,
cambia el mapeo en `docker-compose.yml`:

```yaml
ports:
  - "8080:80"
```

Luego abre `http://localhost:8080/`.

### `docker compose` no encontrado

Usa la sintaxis clásica con guión:

```cmd
docker-compose up -d
```

## SSL

Se genera automáticamente un certificado auto-firmado para dominios `*.test`
al iniciar el contenedor.
