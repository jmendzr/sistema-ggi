# Briefing para la sesión de Claude en el nuevo hosting (Qualyta / cPanel)

> Pega este texto completo como primer mensaje en la ventana de Claude que esté
> conectada al nuevo hosting. Le da todo el contexto para instalar y dejar
> corriendo el sistema.

---

## Contexto de hosting (IMPORTANTE)

- **Destino: Qualyta — hosting administrado con panel cPanel. NO es un VPS.**
- El servidor **actual también es hosting administrado** (DirectAdmin /
  InterServer / LiteSpeed), del que se migran código, base de datos y archivos.
- Por ser hosting cPanel: **NO** se instala el stack por consola (nada de
  `apt`, Apache a mano, ni root). PHP, MySQL, cron y SSL se configuran **desde
  cPanel** (MultiPHP Manager, MySQL Databases, Cron Jobs, SSL/TLS). Las tareas son
  a nivel de **aplicación**.

## Tarea

Instalar y dejar funcionando en Qualyta (cPanel) el "Sistema GGI", una aplicación
web PHP. El código está en GitHub y trae documentos de referencia:

- `README.md` — qué es el sistema, arquitectura, módulos, tablas y variables.
- **`MIGRACION_CPANEL.md`** — **guía principal, paso a paso para cPanel: SÍGUELA.**
- `MIGRACION_VPS.md` — solo referencia alterna (para VPS); en Qualyta no aplica.

**Repositorio:** `git@github.com:jmendzr/sistema-ggi.git`

Primer paso: clónalo/léelo y abre `README.md` + `MIGRACION_CPANEL.md`. Luego
ejecuta esa guía paso a paso, confirmando conmigo antes de acciones irreversibles
(importar BD, cambiar DNS, emitir facturas reales).

---

## Qué es el sistema (resumen)

- CRM/gestión documentaria para una consultora ISO (propuestas, certificados,
  clientes, envíos/couriers, facturación SUNAT vía Alegra, inbox de WhatsApp,
  bonos, planilla, reportes).
- **PHP 8.3** monolito (sin framework, patrón page-controller), **MySQL/MariaDB**
  (`ggi_sistema`, utf8mb4), front controller `index.php`, SPA propia + **PWA**
  (manifest + service worker + Web Push VAPID).
- ~380 archivos, 28 módulos, ~66 tablas, **10 cron jobs**, varios webhooks.

## Requisitos (configurar en cPanel)

- **PHP 8.3** (MultiPHP Manager) + extensiones: `pdo_mysql, curl, mbstring, json,
  zip, gd, fileinfo, openssl, intl, opcache` (Select PHP Version).
- En *Select PHP Version → Options*: `session.gc_maxlifetime=2592000`,
  `session.cookie_lifetime=2592000`, `upload_max_filesize`/`post_max_size` ≥ 64M,
  `memory_limit` ≥ 256M.
- **BD MySQL** creada en *MySQL Databases* (cPanel le pone prefijo `USUARIO_`).
- **Composer** por Terminal si existe; si no, subir `vendor/` ya instalada.
- **SSL** (AutoSSL/Let's Encrypt del panel).
- El hosting respeta **`.htaccess`** (LiteSpeed/Apache) — requerido.

## Estructura de carpetas (clave)

El `.env` va **una carpeta por encima** del DocumentRoot (config.php lo busca en
`../../.env`). Si el DocumentRoot es `/home/USUARIO/public_html`, el `.env` va en
`/home/USUARIO/.env`.

```
/home/USUARIO/
├── .env              ← secretos, permisos 600, FUERA de la raíz web
└── public_html/      ← DocumentRoot = el repo
    ├── config/ core/ modules/ assets/ portal/ cron/ sql/ templates/
    ├── documentaciones_clientes/   (escribible; migrar, ~869 MB)
    ├── uploads/                    (escribible)
    ├── storage/                    (caché PDF; escribible; NO migrar, se regenera)
    └── logs/                       (escribible)
```

## Variables de entorno

Copiar `public_html/.env.example` a `../.env`. Ojo en cPanel:
- `DB_NAME`/`DB_USER` llevan el **prefijo de cuenta** (`USUARIO_ggisistema`,
  `USUARIO_xxx`), no `ggi_sistema` pelado.
- `VAPID_*` → **las MISMAS de producción** (o se caen las suscripciones push).
- `ALEGRA_STAMP=0` hasta validar (no emitir facturas reales a SUNAT).

## Datos a migrar (desde el hosting actual)

1. **BD:** `mysqldump --single-transaction --routines --triggers` → en el `.sql`
   **quitar `CREATE DATABASE`/`USE`** → importar por phpMyAdmin (o SSH) a la BD con
   prefijo. Usar **datos reales**, no el esquema del repo.
2. **Archivos:** `documentaciones_clientes/` (~869 MB) + `uploads/` por rsync (SSH)
   o tar.gz + File Manager. `storage/` no hace falta.

## Cron (10 tareas — cPanel → Cron Jobs)

Usar la **ruta del PHP 8.3 del hosting** (confirmar: p.ej.
`/usr/local/bin/php` o `/opt/cpanel/ea-php83/root/usr/bin/php`). Tabla en
`MIGRACION_CPANEL.md` §9. Alternativa: disparo por URL con `?token=CRON_TOKEN`.

## Webhooks

- Despliegue: `.cpanel.yml` (Git deploy nativo) o `deploy.php` (secret DEPLOY_SECRET).
- WhatsApp Meta: Callback URL nueva + WA_WEBHOOK_VERIFY_TOKEN.

## Gotchas críticos

- **HTTPS obligatorio** (login con `cookie_secure=1`): emitir SSL antes de probar.
- **Prefijo cPanel** en BD/usuario.
- **Sesiones 30 días** (`gc_maxlifetime`/`cookie_lifetime` ≥ 2592000).
- **Mismas VAPID** que producción.
- **Alegra en borrador** hasta validar.
- `.htaccess` bloquea acceso web a `config/ core/ sql/ logs/ cron/` y `.git`.

## Verificación final

Login HTTPS · subir y previsualizar documento · propuesta PDF (dompdf) · push ·
recibir WhatsApp en inbox · correr un cron a mano · deploy · logs sin errores.

---

*Credenciales reales (DB, tokens, llaves): las tengo yo y las pondré en el `.env`;
no están en el repositorio.*
