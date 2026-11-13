# Migración del Sistema GGI a hosting cPanel (Qualyta)

Guía paso a paso para desplegar el sistema (PHP 8.3 + MySQL + cron + PWA) en un
**hosting administrado con cPanel** (Qualyta). No se instala stack por consola:
todo se hace desde el panel. El servidor de origen también es hosting administrado.

> Si en algún paso el hosting te da **acceso SSH / Terminal**, úsalo (es más rápido
> para git, composer y rsync). Si no, cada paso tiene su equivalente por panel
> (File Manager, phpMyAdmin, Cron Jobs, etc.).

---

## 0. Inventario

| Componente | Detalle |
|---|---|
| PHP | **8.3** (selector de versión de cPanel) |
| Extensiones | `pdo_mysql, curl, mbstring, json, zip, gd, fileinfo, openssl, intl, opcache` |
| Base de datos | MySQL/MariaDB `ggi_sistema` (utf8mb4) — en cPanel llevará prefijo de cuenta |
| Servidor web | LiteSpeed/Apache (cPanel) con **`.htaccess`** |
| Front controller | `index.php` |
| Dependencias | Composer (`dompdf`, `phpword`, `web-push`) |
| Cron | 10 scripts en `cron/` |
| Secretos | `.env` **fuera** de la raíz web (un nivel arriba del DocumentRoot) |
| Datos | `documentaciones_clientes/` (~869 MB), `uploads/`, `storage/` (regenerable) |

---

## 1. Dominio / subdominio en cPanel

Decide dónde vivirá el sistema y anota su **Document Root**:
- **Dominio principal** → DocumentRoot `/home/USUARIO/public_html`
- **Subdominio** (ej. `sistema.tudominio.com`) → en cPanel → *Domains / Subdomains*,
  crea el subdominio; su DocumentRoot será algo como `/home/USUARIO/sistema` o
  `/home/USUARIO/public_html/sistema`.

> **Clave:** el `.env` debe quedar en la carpeta **inmediatamente superior** al
> DocumentRoot (config.php lo busca en `../../.env`). Si el DocumentRoot es
> `/home/USUARIO/public_html`, el `.env` va en `/home/USUARIO/.env`.

---

## 2. PHP 8.3 + extensiones (panel)

1. cPanel → **MultiPHP Manager** → selecciona el dominio → versión **PHP 8.3** →
   *Apply*.
2. cPanel → **Select PHP Version** (o *PHP Extensions*) → marca:
   `pdo_mysql, curl, mbstring, json, zip, gd, fileinfo, openssl, intl, opcache`.
3. En **Select PHP Version → Options**, verifica/ajusta:
   - `session.gc_maxlifetime = 2592000`
   - `session.cookie_lifetime = 2592000`
   - `upload_max_filesize` y `post_max_size` ≥ tamaño de los PDFs/documentos (ej. 64M)
   - `memory_limit` ≥ 256M (dompdf)

> El repo trae `.user.ini` y `.htaccess` con los valores de sesión/opcache; en
> cPanel conviene además fijarlos en *Options* por si el panel los sobreescribe.

---

## 3. Subir el código

**Opción A — Git Version Control (recomendada):**
cPanel → **Git Version Control** → *Create* →
- Clone URL: `https://github.com/jmendzr/sistema-ggi.git`
  (para repo privado usa un token/Deploy Key; o clona por SSH si hay Terminal)
- Repository Path: el DocumentRoot del dominio
- Crea y despliega.

**Opción B — File Manager / SFTP:**
Descarga el repo como ZIP, súbelo al DocumentRoot por **File Manager** y extrae;
o sube por SFTP. (En este caso no tendrás auto-deploy por git.)

**Dependencias (`vendor/`):**
- Si hay **Terminal/SSH**: en el DocumentRoot, `composer install --no-dev --optimize-autoloader`.
- Si NO hay composer: sube la carpeta `vendor/` ya instalada por SFTP/File Manager.

---

## 4. Carpetas escribibles y permisos

Desde File Manager (o SSH), asegúrate de que existan y tengan escritura:
```
public_html/documentaciones_clientes/   (755/775)
public_html/uploads/                     (755/775)
public_html/storage/                     (755/775)
public_html/logs/                        (755/775)
```
En cPanel el usuario de PHP suele ser tu propio usuario de cuenta, así que 755
basta normalmente.

---

## 5. Base de datos (panel)

1. cPanel → **MySQL Databases**:
   - *Create New Database*: ej. `ggisistema` → cPanel la nombrará `USUARIO_ggisistema`.
   - *Add New User*: crea usuario + contraseña fuerte.
   - *Add User To Database*: asígnale **ALL PRIVILEGES**.
2. Anota el **nombre real con prefijo** (`USUARIO_ggisistema`) y el usuario
   (`USUARIO_xxx`): esos van en el `.env` (ver §6), no el `ggi_sistema` pelado.
3. **Importar los datos reales** desde el hosting actual:
   - En el origen, exporta: `mysqldump --single-transaction --routines --triggers
     -u U -p ggi_sistema > ggi.sql` (o por phpMyAdmin → Export).
   - **Edita el `.sql`**: quita las líneas `CREATE DATABASE ...` y `USE ...` (en
     cPanel la BD ya existe con prefijo).
   - cPanel → **phpMyAdmin** → selecciona `USUARIO_ggisistema` → *Import* → sube el
     `.sql`. Si pesa mucho, **comprímelo en `.gz`** (phpMyAdmin acepta gzip) o
     impórtalo por SSH: `mysql -u USUARIO_x -p USUARIO_ggisistema < ggi.sql`.

> Usa los **datos reales** (mysqldump de producción). `sql/ggi_sistema.sql` del
> repo es solo el esquema base.

---

## 6. Archivo `.env` (fuera de la raíz web)

1. File Manager → ve a la carpeta **superior** al DocumentRoot (ej. `/home/USUARIO/`).
   Activa *Show Hidden Files*.
2. Copia el contenido de `public_html/.env.example` a un nuevo archivo `.env` ahí.
3. Rellena con las credenciales reales:
   - `APP_URL=https://sistema.tudominio.com`, `APP_ENV=production`
   - `DB_HOST=localhost`, `DB_NAME=USUARIO_ggisistema`, `DB_USER=USUARIO_xxx`, `DB_PASS=...`
   - `CRON_TOKEN`, `DEPLOY_SECRET`, `WA_WEBHOOK_VERIFY_TOKEN`
   - `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` → **las MISMAS de producción**
   - WhatsApp (Meta/Kapso), Meta Ads, Alegra (**`ALEGRA_STAMP=0` hasta validar**),
     couriers, IA (opcional)
4. Permisos del `.env`: **0600**.

---

## 7. Migrar los archivos de clientes (~1 GB)

- **Con SSH:** `rsync -avz USUARIO@origen:ruta/public_html/documentaciones_clientes/
  ~/public_html/documentaciones_clientes/` (y lo mismo con `uploads/`).
- **Sin SSH:** comprime en el origen (`tar czf docs.tar.gz documentaciones_clientes`),
  descárgalo y súbelo por File Manager, luego extrae. `storage/` no hace falta.
- Reajusta permisos de escritura tras copiar.

---

## 8. SSL / HTTPS (panel)

cPanel → **SSL/TLS Status** o **Let's Encrypt** → emite el certificado para el
dominio/subdominio (AutoSSL suele hacerlo solo).

> **El login exige HTTPS** (`session.cookie_secure=1`). No pruebes login por HTTP.

---

## 9. Cron Jobs (panel)

cPanel → **Cron Jobs**. Usa la **ruta del binario PHP 8.3** del hosting (en cPanel
suele ser `/usr/local/bin/php` o `/opt/cpanel/ea-php83/root/usr/bin/php` — confírmala
con soporte de Qualyta o en *Select PHP Version*). Ejemplo:

```
# Notificaciones (certificados/auditorías/cotizaciones)
0 8 * * *    /usr/local/bin/php /home/USUARIO/public_html/cron/notificaciones.php
0 9 * * *    /usr/local/bin/php /home/USUARIO/public_html/cron/recordatorio_certificados.php
0 9 * * *    /usr/local/bin/php /home/USUARIO/public_html/cron/recordatorio_cotizacion_vencer.php
0 10 * * *   /usr/local/bin/php /home/USUARIO/public_html/cron/recordatorio_datos_envio.php
*/5 * * * *  /usr/local/bin/php /home/USUARIO/public_html/cron/recordatorio_llamadas.php
*/5 * * * *  /usr/local/bin/php /home/USUARIO/public_html/cron/wa_campanas.php
*/30 * * * * /usr/local/bin/php /home/USUARIO/public_html/cron/sync_tracking_olva.php
0 */2 * * *  /usr/local/bin/php /home/USUARIO/public_html/cron/facturas_sunat_recheck.php
0 11 * * *   /usr/local/bin/php /home/USUARIO/public_html/cron/sugerir_no_enviado.php
0 3 * * *    /usr/local/bin/php /home/USUARIO/public_html/cron/limpiar_cache_pdf.php
```

> Alternativa sin CLI: dispara por URL con token, p. ej.
> `wget -q -O /dev/null "https://sistema.tudominio.com/cron/notificaciones.php?token=CRON_TOKEN"`.
> Confirma las frecuencias con el crontab del hosting actual antes de fijarlas.

---

## 10. Webhooks

- **Despliegue:** si usaste *Git Version Control*, puedes auto-desplegar con un
  `.cpanel.yml` (deploy nativo de cPanel) o seguir usando `deploy.php`
  (`https://sistema.tudominio.com/deploy.php`, secret `DEPLOY_SECRET`).
- **WhatsApp (Meta):** actualiza la Callback URL del webhook a la nueva URL y
  revalida con `WA_WEBHOOK_VERIFY_TOKEN`.

---

## 11. Corte de DNS (cutover)

1. Baja el **TTL** del registro A a 300 s un día antes.
2. Última pasada: `mysqldump` fresco + `rsync` de archivos, justo antes del corte.
3. Apunta el **A** del dominio a la IP del hosting de Qualyta (o cambia los
   **nameservers** a los de Qualyta si migras el dominio completo).
4. Confirma SSL (AutoSSL) sobre el nuevo destino.
5. Verifica con el checklist.
6. Deja el hosting viejo activo 1–2 semanas como respaldo (rollback = reapuntar DNS).

---

## 12. Checklist post-cutover

- [ ] Login por HTTPS (la sesión persiste 30 días).
- [ ] Subir un documento de cliente y previsualizarlo (lightbox).
- [ ] Generar una propuesta PDF (dompdf) sin errores.
- [ ] Notificación push (VAPID) a un navegador suscrito.
- [ ] Recibir un WhatsApp en el inbox (webhook Meta OK).
- [ ] Un cron corre a mano sin error.
- [ ] `deploy.php` / Git deploy actualiza el sitio.
- [ ] `logs/` y el error log de cPanel sin errores nuevos.
- [ ] Alegra en borrador antes de pasar `ALEGRA_STAMP=1`.

---

## Gotchas específicos de cPanel

- **Prefijo de BD/usuario:** cPanel antepone `USUARIO_` al nombre de la base y del
  usuario. En el `.env` va el nombre **con prefijo**, no `ggi_sistema` pelado.
- **`CREATE DATABASE`/`USE` en el dump:** quítalos antes de importar (la BD ya
  existe con prefijo).
- **`.env` ubicación:** una carpeta **arriba** del DocumentRoot; en subdominios
  revisa cuál es el DocumentRoot real.
- **Ruta del PHP CLI** para cron: confírmala (no asumas `php` a secas).
- **`.htaccess` activo:** cPanel/LiteSpeed lo respeta; mantiene reescrituras y
  bloqueo de `config/ core/ sql/ logs/ cron/`.
- **Límites de import en phpMyAdmin:** si el `.sql` es grande, usa `.gz` o SSH.

---

*Ajusta `USUARIO`, dominio y rutas a tu cuenta real de Qualyta antes de ejecutar.*
