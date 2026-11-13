# Migración del Sistema GGI a un VPS

Guía paso a paso para mover el sistema (PHP 8.3 + MySQL + cron + PWA) desde el
hosting actual (DirectAdmin/InterServer/LiteSpeed) a un VPS propio, con el menor
riesgo y sin tiempo fuera de servicio apreciable.

> **Resumen de la decisión de hosting:** este es un monolito PHP con estado en el
> servidor (MySQL, 10 cron jobs, ~1 GB de archivos de clientes, sesiones de 30
> días, webhooks entrantes de WhatsApp y despliegue). Por eso **Vercel no sirve**
> (serverless/JS) y un **VPS es el encaje natural**. Render solo valdría con disco
> persistente + MySQL externo + crons como servicios aparte: más complejo para el
> mismo resultado.

---

## 0. Antes de empezar — inventario

| Componente | Detalle actual |
|---|---|
| Lenguaje | PHP **8.3** (NTS) |
| Extensiones PHP | `pdo_mysql`, `curl`, `mbstring`, `json`, `zip`, `fileinfo`, `openssl`, `gd` |
| Base de datos | MySQL/MariaDB (`ggi_sistema`, utf8mb4) |
| Servidor web | LiteSpeed/Apache con `.htaccess` (mod_rewrite + `php_value`) |
| Front controller | `index.php` (todo lo no-archivo se reescribe ahí) |
| Dependencias | Composer (`dompdf`, `phpword`, `web-push`) → `composer install` |
| Cron | 10 scripts en `cron/` (CLI o web con token) |
| Secretos | `.env` **fuera** de `public_html` (en el directorio que lo contiene) |
| Datos en disco | `documentaciones_clientes/` (~869 MB), `uploads/`, `storage/` (caché PDF ~129 MB, regenerable) |
| PWA | `manifest.php` + `sw.js` + Web Push (VAPID) |
| Webhooks entrantes | WhatsApp (Meta), `deploy.php` (GitHub) |

**Recomendación de servidor:** para reutilizar el `.htaccess` tal cual (usa
`php_value` y `mod_rewrite`), lo más simple es **Apache** o **OpenLiteSpeed**
(ambos leen `.htaccess`). Si prefieres Nginx, hay que traducir las reglas (ver
Anexo B).

**Dimensionamiento inicial sugerido:** 2 vCPU · 4 GB RAM · **80 GB SSD**
(los datos ocupan ~1 GB hoy; 80 GB da margen de años). Proveedores: Hetzner,
DigitalOcean, Linode/Akamai, Vultr, Contabo. Si quieres panel tipo DirectAdmin
para no administrar por consola, contrata el VPS **con DirectAdmin** y la
migración es casi idéntica a tu entorno actual.

---

## 1. Provisionar el VPS

1. Crea el VPS con **Ubuntu 24.04 LTS**.
2. Accede por SSH como root y crea un usuario no-root con sudo:
   ```bash
   adduser ggi
   usermod -aG sudo ggi
   ```
3. Copia tu llave pública para entrar sin contraseña y endurece SSH
   (`PasswordAuthentication no`, `PermitRootLogin no` en `/etc/ssh/sshd_config`,
   luego `systemctl restart ssh`).
4. Firewall:
   ```bash
   sudo ufw allow OpenSSH
   sudo ufw allow 80,443/tcp
   sudo ufw enable
   ```

---

## 2. Instalar el stack (ruta Apache — la más simple)

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mariadb-server \
  php8.3 php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring \
  php8.3-zip php8.3-gd php8.3-xml php8.3-intl php8.3-opcache \
  libapache2-mod-fcgid composer unzip git

# PHP-FPM + Apache
sudo a2enmod rewrite headers proxy_fcgi setenvif expires
sudo a2enconf php8.3-fpm
sudo systemctl restart apache2
```

> Si eliges **OpenLiteSpeed** en vez de Apache, instala `openlitespeed` +
> `lsphp83` y sus extensiones (`lsphp83-mysql`, etc.). También lee `.htaccess`.

Asegura la base de datos:
```bash
sudo mysql_secure_installation
```

---

## 3. Estructura de carpetas (clave: `.env` fuera de la raíz web)

Replica la jerarquía actual: el `.env` debe quedar **un nivel arriba** de
`public_html` (así lo busca `config/config.php` en `../../.env`).

```
/var/www/sistema/            ← aquí va el .env
└── public_html/             ← DocumentRoot (el repo clonado)
```

```bash
sudo mkdir -p /var/www/sistema
sudo chown -R ggi:ggi /var/www/sistema
cd /var/www/sistema
git clone git@github.com:jmendzr/sistema-ggi.git public_html
cd public_html
composer install --no-dev --optimize-autoloader
```

Permisos de carpetas escribibles por PHP:
```bash
mkdir -p documentaciones_clientes uploads storage logs
sudo chown -R www-data:www-data documentaciones_clientes uploads storage logs
sudo find documentaciones_clientes uploads storage logs -type d -exec chmod 775 {} \;
```

---

## 4. Configurar el `.env`

```bash
cp /var/www/sistema/public_html/.env.example /var/www/sistema/.env
nano /var/www/sistema/.env
```

Rellena con las credenciales reales (DB, VAPID, WhatsApp/Meta, Alegra, IA…).
Ajusta como mínimo:
- `APP_URL=https://sistema.groupglobaliso.com`
- `APP_ENV=production`
- `DB_HOST=localhost`, `DB_NAME=ggi_sistema`, `DB_USER=...`, `DB_PASS=...`
- `CRON_TOKEN`, `DEPLOY_SECRET`, `WA_WEBHOOK_VERIFY_TOKEN` (puedes reusar los
  actuales o generar nuevos con `openssl rand -hex 32`)

Protégelo:
```bash
chmod 600 /var/www/sistema/.env
```

> Las llaves VAPID **deben ser las mismas** que las actuales, o las suscripciones
> push existentes dejarán de funcionar y habrá que re-suscribir a los usuarios.

---

## 5. Migrar la base de datos

En el **servidor actual** (o por SSH), exporta la BD de producción completa:
```bash
mysqldump --single-transaction --routines --triggers \
  -u USUARIO -p ggi_sistema > ggi_sistema_prod.sql
```

Cópiala al VPS (`scp ggi_sistema_prod.sql ggi@IP_DEL_VPS:~`) y en el VPS:
```bash
sudo mysql -e "CREATE DATABASE ggi_sistema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'ggi'@'localhost' IDENTIFIED BY 'TU_PASS';"
sudo mysql -e "GRANT ALL ON ggi_sistema.* TO 'ggi'@'localhost'; FLUSH PRIVILEGES;"
mysql -u ggi -p ggi_sistema < ~/ggi_sistema_prod.sql
```

> El repo trae `sql/ggi_sistema.sql` (esquema base) + migraciones, pero para
> **conservar los datos reales** usa el `mysqldump` de producción, no el esquema.

---

## 6. Migrar los archivos de clientes (~1 GB)

Con `rsync` desde el servidor actual (repetible; la 2.ª pasada solo copia lo
nuevo, ideal para el día del corte):
```bash
rsync -avz --progress \
  USUARIO@servidor-actual:ruta/public_html/documentaciones_clientes/ \
  /var/www/sistema/public_html/documentaciones_clientes/

rsync -avz --progress \
  USUARIO@servidor-actual:ruta/public_html/uploads/ \
  /var/www/sistema/public_html/uploads/
```
`storage/` (caché de PDF) **no hace falta copiarlo**: se regenera solo.

Reajusta permisos tras copiar:
```bash
sudo chown -R www-data:www-data documentaciones_clientes uploads
```

---

## 7. VirtualHost (Apache)

`/etc/apache2/sites-available/sistema.conf`:
```apache
<VirtualHost *:80>
    ServerName sistema.groupglobaliso.com
    DocumentRoot /var/www/sistema/public_html

    <Directory /var/www/sistema/public_html>
        AllowOverride All          # imprescindible: habilita el .htaccess
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/sistema_error.log
    CustomLog ${APACHE_LOG_DIR}/sistema_access.log combined
</VirtualHost>
```
```bash
sudo a2ensite sistema
sudo apache2ctl configtest && sudo systemctl reload apache2
```

> `AllowOverride All` es lo que hace que tu `.htaccess` (reescrituras, bloqueo de
> `config/`, `core/`, `sql/`, cabeceras de caché) funcione sin tocar nada.

---

## 8. HTTPS (Let's Encrypt)

Haz esto **después** del corte de DNS (necesita que el dominio apunte al VPS),
o usa un subdominio de prueba primero.
```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d sistema.groupglobaliso.com
```
Renovación automática ya queda programada por certbot.

> El `.htaccess` fuerza `session.cookie_secure=1`: el sistema **requiere HTTPS**
> para iniciar sesión. No pruebes el login por HTTP plano.

---

## 9. Cron jobs

Edita el crontab del usuario de PHP (`www-data`) o de `ggi` con rutas absolutas.
`crontab -e` y añade (ajusta horarios a tu operación; todos usan PHP CLI):

```cron
# Notificaciones automáticas (certificados/auditorías/cotizaciones)
0 8 * * *    php /var/www/sistema/public_html/cron/notificaciones.php
# Recordatorios
0 9 * * *    php /var/www/sistema/public_html/cron/recordatorio_certificados.php
0 9 * * *    php /var/www/sistema/public_html/cron/recordatorio_cotizacion_vencer.php
0 10 * * *   php /var/www/sistema/public_html/cron/recordatorio_datos_envio.php
*/5 * * * *  php /var/www/sistema/public_html/cron/recordatorio_llamadas.php
# WhatsApp / campañas / tracking
*/5 * * * *  php /var/www/sistema/public_html/cron/wa_campanas.php
*/30 * * * * php /var/www/sistema/public_html/cron/sync_tracking_olva.php
# Facturación SUNAT (reintento de estado)
0 */2 * * *  php /var/www/sistema/public_html/cron/facturas_sunat_recheck.php
# Sugerir "no enviado" + limpieza de caché PDF
0 11 * * *   php /var/www/sistema/public_html/cron/sugerir_no_enviado.php
0 3 * * *    php /var/www/sistema/public_html/cron/limpiar_cache_pdf.php
```

> Confirma las frecuencias exactas revisando el crontab del servidor actual
> (`crontab -l`) antes de cambiarlas; arriba hay valores razonables de partida.

---

## 10. Webhooks (apuntar a la nueva URL)

- **Despliegue (`deploy.php`):** en GitHub (`jmendzr/sistema-ggi` → Settings →
  Webhooks) apunta a `https://sistema.groupglobaliso.com/deploy.php`, Content-type
  `application/json`, Secret = `DEPLOY_SECRET`. Verifica que el VPS puede hacer
  `git pull` (la llave desplegada ya quedó configurada en el clon).
- **WhatsApp (Meta):** en el panel de Meta, actualiza la Callback URL del webhook
  a la nueva URL y revalida con `WA_WEBHOOK_VERIFY_TOKEN`.

---

## 11. Corte de DNS (cutover)

1. **Baja el TTL** del registro A de `sistema.groupglobaliso.com` a 300 s un día
   antes, para que el cambio propague rápido.
2. Haz una última pasada de `rsync` (archivos) y un `mysqldump` fresco (datos)
   justo antes del corte, para no perder lo creado durante la preparación.
3. Cambia el registro **A** para que apunte a la IP del VPS.
4. Emite el certificado HTTPS (paso 8) si no lo hiciste por subdominio.
5. Verifica con el checklist de abajo.
6. Mantén el servidor viejo encendido 1–2 semanas como respaldo.

---

## 12. Checklist post-cutover

- [ ] Login por HTTPS funciona (sesión persiste).
- [ ] Carga de un documento de cliente y su previsualización (lightbox).
- [ ] Generación de una propuesta PDF (dompdf) sin errores.
- [ ] Envío de una notificación push (VAPID) a un navegador suscrito.
- [ ] Recepción de un mensaje de WhatsApp en el inbox (webhook Meta OK).
- [ ] Un cron corre sin error: `php cron/notificaciones.php` a mano.
- [ ] `deploy.php`: un push a GitHub actualiza el VPS.
- [ ] Revisar `logs/` y el `error.log` de Apache sin errores nuevos.
- [ ] Facturación Alegra en borrador antes de pasar `ALEGRA_STAMP=1`.

---

## Anexo A — Rollback

Si algo falla tras el corte, vuelve a apuntar el DNS a la IP del servidor
antiguo (sigue intacto). Como el TTL está en 300 s, la reversión es casi
inmediata. No borres datos del servidor viejo hasta validar 1–2 semanas.

## Anexo B — Si usas Nginx en vez de Apache/LiteSpeed

Nginx **no lee `.htaccess`**: hay que trasladar las reglas al server block.
Equivalente del front controller y bloqueos:
```nginx
server {
    listen 80;
    server_name sistema.groupglobaliso.com;
    root /var/www/sistema/public_html;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~* ^/(config|core|sql|logs|cron)/ { deny all; }
    location ~ /\.git { deny all; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # cabeceras de caché de assets (equivalente al .htaccess)
    location ~* \.(css|js|woff2?|png|jpg|svg|ico)$ {
        expires 30d; add_header Cache-Control "public, immutable";
    }
}
```
Además, en Nginx los `php_value` del `.htaccess`/`.user.ini` de sesión/OPcache se
configuran en el pool de PHP-FPM (`/etc/php/8.3/fpm/pool.d/www.conf` o un
`.user.ini` que PHP-FPM también respeta).

---

*Documento generado para la migración del Sistema GGI. Ajusta rutas, usuarios y
horarios de cron a tu entorno real antes de ejecutar.*
