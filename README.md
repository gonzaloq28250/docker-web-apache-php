# Docker Web Apache PHP

Contenedor Docker con Apache 2.4 + PHP 8.3 como reemplazo portable de Laragon.

## Requisitos

- [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/) (Windows)
- [Git](https://git-scm.com/download/win)

## Instalación paso a paso (Windows)

### 1. Abrir **CMD como Administrador**

### 2. Clonar el repositorio

```cmd
cd C:\
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git
cd docker-web-apache-php
```

### 3. (Opcional) Usar una carpeta www existente

Si ya tienes proyectos en otra carpeta (ej. `C:\gqa\www`), crea el archivo `.env`:

```cmd
echo WWW_PATH=C:\gqa\www > .env
```

Si **no** creas el `.env`, usará la carpeta `www/` que está dentro del repositorio.

### 4. Iniciar el contenedor

```cmd
docker compose up -d
```

La primera vez descargará e instalará todo (~5-10 minutos).

### 5. Probar

```cmd
curl http://localhost/
```

Deberías ver una página de estado con "Operativo" y PHP 8.3.

### 6. Agregar tus proyectos

Crea carpetas dentro de tu `www` (el que definiste en el paso 3 o el del repo):

```cmd
mkdir www\miproyecto
echo ^<?php phpinfo(); ^> > www\miproyecto\index.php
```

Abre `http://localhost/miproyecto/index.php`

### 7. Detener el contenedor

```cmd
docker compose down
```

## Solución de problemas

### Error: `exec /usr/local/bin/entrypoint.sh: no such file or directory`

```cmd
rmdir /s /q C:\docker-web-apache-php
cd C:\
git clone https://github.com/gonzaloq28250/docker-web-apache-php.git
cd docker-web-apache-php
echo WWW_PATH=C:\gqa\www > .env
docker compose up -d
```

### Error: `port is already allocated` (puerto 80 ocupado)

En `docker-compose.yml` cambia:

```yaml
ports:
  - "8080:80"
```

Luego inicia y abre `http://localhost:8080/`.

### Error: `'docker compose' no se reconoce`

```cmd
docker-compose up -d
```

## SSL

Se genera automáticamente un certificado auto-firmado para dominios `*.test`
al iniciar el contenedor.
