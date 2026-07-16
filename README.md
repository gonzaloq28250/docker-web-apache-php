# Docker Web Apache PHP

Contenedor Docker con Apache 2.4 + PHP 8.3 como reemplazo portable de Laragon para desarrollo local.

## Requisitos

- Docker Desktop (Windows) o Docker Engine (Linux)
- Git

## Uso rápido

```bash
# Clonar
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git
cd docker-web-apache-php

# Usar ruta www personalizada (opcional)
echo "WWW_PATH=D:/mis-proyectos/www" > .env

# Iniciar
docker compose -f docker/docker-compose.yml up -d

# Probar
curl http://localhost/
```

## Configuración

### Variable WWW_PATH

Por defecto el document root apunta a `www/` (en la raíz del repo).
Para usar un directorio existente, crea un archivo `.env` en la **raíz del repo**:

```env
WWW_PATH=C:/ruta/a/tu/www
```

> El `.env` debe estar en la misma carpeta desde donde ejecutas `docker compose`,
> normalmente la raíz del repo (`repo-root/.env`), no dentro de `docker/`.

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

```bash
# Opción 1 (recomendado): re-clonar con la nueva configuración
rm -rf docker-web-apache-php
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git

# Opción 2: corregir sin re-clonar
git add --renormalize docker/entrypoint.sh
docker compose -f docker/docker-compose.yml build --no-cache
```

### Puerto 80 ocupado

Si otro programa (IIS, World Wide Web Publishing, Skype) usa el puerto 80:

```bash
# Cambiar el puerto en docker/docker-compose.yml
# "8080:80" en lugar de "80:80"
# Luego abrir http://localhost:8080/
```

### `docker compose` no encontrado

Usa la sintaxis clásica con guión:

```bash
docker-compose -f docker/docker-compose.yml up -d
```

## SSL

Se genera automáticamente un certificado auto-firmado para dominios `*.test`
al iniciar el contenedor.
