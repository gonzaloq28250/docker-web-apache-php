# ARCHIVO .HTACCESS PARA APACHE
# Este archivo debe renombrarse a .htaccess (sin extensión .js)
# Proporciona configuraciones de seguridad y redirecciones para Apache

# Habilitar reescritura de URLs
RewriteEngine On

# Proteger archivo de configuración
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>

# Prevenir listado de directorios
Options -Indexes

# Proteger contra inyección de scripts
<IfModule mod_headers.c>
    # Prevenir XSS
    Header set X-XSS-Protection "1; mode=block"
    
    # Prevenir Clickjacking
    Header always append X-Frame-Options SAMEORIGIN
    
    # Prevenir MIME sniffing
    Header set X-Content-Type-Options nosniff
    
    # Content Security Policy básico
    Header set Content-Security-Policy "default-src 'self' 'unsafe-inline' 'unsafe-eval'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';"
    
    # Referrer Policy
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Configuración de tipos MIME
<IfModule mod_mime.c>
    AddType application/json .json
    AddType application/javascript .js
    AddType text/css .css
    AddType text/html .html
</IfModule>

# Compresión GZIP para mejor rendimiento
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json
</IfModule>

# Cache control
<IfModule mod_expires.c>
    ExpiresActive On
    
    # HTML no se cachea
    ExpiresByType text/html "access plus 0 seconds"
    
    # CSS y JavaScript - 1 semana
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    
    # JSON no se cachea (datos dinámicos)
    ExpiresByType application/json "access plus 0 seconds"
</IfModule>

# Límites de tamaño de petición
<IfModule mod_php7.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value max_execution_time 30
    php_value max_input_time 30
</IfModule>

# Error pages personalizados (opcional)
# ErrorDocument 404 /404.html
# ErrorDocument 500 /500.html

# Prevenir acceso a archivos sensibles
<FilesMatch "\.(htaccess|htpasswd|ini|log|sh|sql|bak|config)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Redirección HTTPS (descomenta si usas HTTPS)
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Protección contra hotlinking (opcional)
# RewriteCond %{HTTP_REFERER} !^$
# RewriteCond %{HTTP_REFERER} !^http(s)?://(www\.)?tudominio.com [NC]
# RewriteRule \.(jpg|jpeg|png|gif|svg)$ - [NC,F,L]