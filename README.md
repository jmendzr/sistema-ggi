# GGI Sistema

Sistema de gestión interno (CRM + operaciones) de **Group Global ISO S.A.C.** — consultora de certificaciones ISO en Perú. Cubre el ciclo comercial completo: prospección por WhatsApp, cotizaciones/propuestas, aceptación, certificados, pagos, facturación electrónica SUNAT, envíos con couriers, bonos/comisiones, planilla y reportes.

> Aplicación web **PHP nativo** (sin framework) con navegación tipo SPA y comportamiento PWA (instalable, offline parcial, push). Backend y frontend conviven en el mismo repositorio.

---

## Tabla de contenido

1. [Stack tecnológico](#stack-tecnológico)
2. [Escala del proyecto](#escala-del-proyecto)
3. [Arquitectura](#arquitectura)
4. [Estructura de carpetas](#estructura-de-carpetas)
5. [Backend](#backend)
6. [Frontend](#frontend)
7. [Módulos](#módulos)
8. [Base de datos](#base-de-datos)
9. [Integraciones externas](#integraciones-externas)
10. [Tareas programadas (cron)](#tareas-programadas-cron)
11. [Configuración y variables de entorno](#configuración-y-variables-de-entorno)
12. [Instalación y puesta en marcha](#instalación-y-puesta-en-marcha)
13. [Despliegue](#despliegue)
14. [Convenciones para mantenedores](#convenciones-para-mantenedores)

---

## Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Lenguaje | **PHP 8.3** (sin framework; arquitectura a medida) |
| Base de datos | **MySQL / MariaDB** (PDO, sentencias preparadas), charset `utf8mb4` |
| Frontend | HTML + CSS propio (design system con tokens) + **JavaScript vanilla** (sin build step) |
| Navegación | SPA a medida (`spa-nav.js`) — fetch de la página, swap de `<main>`, re-ejecución de scripts |
| PWA | `manifest.json` + `sw.js` (Service Worker) + Web Push (VAPID) |
| Dependencias PHP (Composer) | `dompdf/dompdf` (PDF), `phpoffice/phpword` (DOCX), `minishlink/web-push` (push VAPID) |
| Hosting | DirectAdmin / InterServer · LiteSpeed (LSAPI) · PHP-FPM · crons por DirectAdmin |
| Control de versiones | Git + GitHub · despliegue automático por webhook (`deploy.php`) |

No hay paso de compilación: los assets (CSS/JS) se sirven tal cual, con *cache-busting* por `?v=filemtime`.

---

## Escala del proyecto

- **244** archivos PHP (sin contar `vendor/`)
- **~76,200** líneas de PHP
- **28** módulos funcionales
- **24** clases de servicio en `core/`
- **66** tablas/vistas en base de datos
- **10** tareas cron

---

## Arquitectura

Patrón **page-controller** (cada archivo PHP es una página o un endpoint), no MVC clásico:

- **Páginas de vista**: `modules/<modulo>/index.php`, `ver.php`, `crear.php`, `editar.php` → incluyen el shell `templates/layout.php` (sidebar + header) y `templates/layout_end.php`.
- **Endpoints AJAX/JSON**: `modules/<modulo>/ajax_*.php` → devuelven JSON (`json_ok()` / `json_error()`), protegidos con CSRF.
- **Lógica de negocio reutilizable**: clases en `core/` (servicios de integración, notificaciones, inbox, helpers).
- **Navegación SPA**: `assets/js/spa-nav.js` intercepta clics internos, trae la página por fetch, reemplaza el `<main>`, re-ejecuta scripts y cachea HTML (opt-out con `data-spa-nocache`).
- **Seguridad**: sesión PHP de 30 días, control de acceso por **rol** (`has_role()`) y por **módulo** (`can()`, respaldado en la tabla `rol_permisos`), verificación **CSRF** en todos los POST.
- **Multi-tenant de números WhatsApp**: inbox multiagente con control de acceso por número (`wa_numero_usuarios`).

Flujo de una petición típica:

```
Navegador ──(SPA fetch)──▶ modules/<x>/index.php
                              │  require core/helpers.php  → session_init(), require_login()
                              │  include templates/layout.php (sidebar, permisos can(), CSRF)
                              │  consulta BD vía Database::get() (PDO)
                              │  include templates/layout_end.php
                              ▼
                           HTML (el SPA intercambia solo el <main>)
```

---

## Estructura de carpetas

```
public_html/
├── index.php                  # Entry point: redirige a dashboard o login
├── manifest.json / manifest.php   # PWA manifest
├── sw.js                      # Service Worker (PWA, offline, push)
├── pwa-reset.php              # Utilidad para resetear el SW/caché del cliente
├── deploy.php                 # Webhook de despliegue GitHub → servidor (HMAC)
├── composer.json / composer.lock
├── .htaccess                  # Rewrites, cabeceras de seguridad, caché
├── .user.ini                  # PHP por directorio (sesión 30d, OPcache)
│
├── config/
│   ├── config.php             # Carga .env + define() de toda la configuración
│   └── email_alerta.json      # Config de correo editable desde UI (runtime, no versionar)
│
├── core/                      # Clases de servicio y helpers (backend)
│   ├── Database.php           # Singleton PDO
│   ├── helpers.php            # Auth, roles, CSRF, utilidades, componentes server-side
│   ├── NotificationService.php    # Motor de notificaciones (evento→reglas→destinatarios)
│   ├── PushService.php        # Web Push (VAPID)
│   ├── WhatsAppService.php    # Envío WhatsApp (Meta Cloud API / Kapso)
│   ├── WaInbox.php            # Registro de mensajes entrantes/salientes del inbox
│   ├── WaBot.php              # Bot de recepción de leads (flujos por nodos)
│   ├── WaCampanas.php / KapsoBroadcastService.php / WaPlantillas.php  # Campañas masivas
│   ├── AlegraService.php / SunatService.php    # Facturación electrónica SUNAT
│   ├── MetaAdsService.php / MetaConversionsService.php  # Meta Ads (gasto + conversiones)
│   ├── Olva*/Shalom*/Serpost* Service.php       # Couriers (sedes + tracking)
│   ├── IaAgente.php           # Copiloto IA del inbox (Claude/OpenAI/Gemini)
│   ├── XlsxTemplateFiller.php # Rellena plantillas .xlsx sin librerías
│   ├── doc_completa.php / pendientes.php / envio_notificar.php
│
├── modules/                   # 28 módulos (cada uno = carpeta de páginas + endpoints)
│   ├── auth/                  # Login, logout, recuperación de contraseña
│   ├── dashboard/             # Panel principal
│   ├── clientes/              # Empresas/clientes (CRM)
│   ├── cotizaciones/          # Cotizaciones/propuestas + PDF
│   ├── certificados/          # Certificados ISO
│   ├── pagos/                 # Registro y validación de pagos
│   ├── facturas/              # Facturación electrónica (Alegra/SUNAT)
│   ├── envios/                # Envíos y couriers (Olva/Shalom/Serpost)
│   ├── inbox/                 # Bandeja WhatsApp multiagente
│   ├── whatsapp/              # Config de números, bot, campañas, plantillas
│   ├── bonos/ · metas/ · planilla/   # Comisiones, metas y sueldos
│   ├── auditorias/ · documentos/ · legal/ · llamadas/ · comunicaciones/
│   ├── reportes/ · historial/ · notificaciones/ · cuenta/ · mantenimiento/
│   └── ajax_*.php             # Endpoints globales (badges, búsqueda, push, etc.)
│
├── portal/                    # Portal externo del cliente (enlace público con token)
├── verificar/                 # Verificador público de certificados
├── templates/                 # Shell: layout.php, layout_end.php, 403.php, PWA, acta .docx
├── assets/                    # Frontend estático
│   ├── css/  (design-system.css, app.css, tom-select)
│   ├── js/   (spa-nav, app, components, softphone, validacion, etc.)
│   ├── icons/ · img/ · splash/ · leaflet/ · vendor/ · usuarios/
├── cron/                      # 10 scripts de tareas programadas
├── sql/                       # Migraciones SQL (orden cronológico) + ggi_sistema.sql
├── db/                        # Esquemas auxiliares (bonos_schema.sql)
├── storage/                   # Runtime: caché de PDF, assets generados (no versionar)
├── uploads/ · documentaciones_clientes/   # Archivos subidos (no versionar)
├── logs/                      # Logs de aplicación (no versionar)
└── vendor/                    # Dependencias Composer (no versionar)
```

> Carpetas **excluidas del repo** (ver `.gitignore`): `vendor/`, `storage/`, `logs/`, `uploads/`, `documentaciones_clientes/`, `.env*`, `config/email_alerta.json`, `.claude/`.

---

## Backend

### Punto de entrada y bootstrap

- `index.php` redirige al dashboard (si hay sesión) o al login.
- Todo script hace `require_once core/helpers.php` → que incluye `config/config.php` (carga `.env`) y `core/Database.php`.

### Conexión a base de datos

`core/Database.php` expone un **singleton PDO**: `Database::get()`. Siempre sentencias preparadas (`prepare()/execute()`), `utf8mb4`.

### Autenticación, roles y permisos (`core/helpers.php`)

| Función | Propósito |
|---------|-----------|
| `session_init()` | Inicializa la sesión (nombre `ggi_session`, 30 días). |
| `require_login()` | Exige sesión; si no, redirige a login. |
| `auth_user()` / `is_logged()` | Usuario actual / booleano. |
| `has_role(...$roles)` | `true` si el rol del usuario está en la lista. |
| `require_role(...$roles)` | Login + rol, o corta. |
| `can($modulo, $accion='ver')` | Permiso por módulo. `gerente_general` siempre `true`; el resto se resuelve con la tabla `rol_permisos` (fallback hardcodeado si la tabla no existe). |
| `csrf_token()` / `csrf_field()` / `csrf_verify()` | Protección CSRF (campo `_csrf` o header `X-CSRF-Token`). |
| `json_ok()` / `json_error()` | Respuestas JSON estándar de endpoints AJAX. |
| `log_actividad()` | Auditoría de acciones (tabla `log_actividad`). |

**Roles del sistema**: `gerente_general` (acceso total, inmutable), `gerente_operaciones`, `gerente_comercial`, `ejecutivo_ventas`, `administracion_finanzas` (y roles históricos `gestor_logistico`, `delivery` en el fallback).

### Utilidades de dominio destacadas

`cert_calcular_fechas()`, `cert_meses_vigencia()`, `cert_norma_solo_es()` (certificados), `acta_alcance_requerida()`, `next_codigo()` (correlativos), `ggi_pager()` (paginación canónica), `ggi_msdrop()` (multiselect), `ruc_valido()`, `cache_remember()`, `imagen_reducir()`.

---

## Frontend

Sin framework ni build. La UI se arma desde:

### Shell (`templates/layout.php` + `layout_end.php`)
Sidebar con permisos (`can()`), header con notificaciones/badges, modo `embed` (para abrir fichas dentro de modales iframe), precálculo de permisos y liberación temprana del lock de sesión por rendimiento.

### Design system (`assets/css/design-system.css`)
Sistema canónico con **tokens** (`--bg-surface`, `--brd`, `--tx`, `--ac`, …) y aliases legacy. Clases canónicas: `.data-table`, `.btn-ic`, `.action-btns`, `.empty-state`, componentes de formulario globales (checkboxes/radios/file/date), **tema claro/oscuro** por tokens. Regla: **no redefinir por módulo** (causa *drift*).

### JavaScript (`assets/js/`)

| Archivo | Rol |
|---------|-----|
| `spa-nav.js` | Navegación SPA (fetch + swap `<main>` + prefetch en hover/mousedown/touchstart). |
| `app.js` | Lógica general de UI y listados. |
| `components.js` | Componentes reutilizables (modales GGI, menús de fila, toasts). |
| `validacion.js` | Validación de formularios cliente (GGIForm). |
| `softphone.js` | Softphone / llamadas WhatsApp (Calling API). |
| `push-notif.js` | Suscripción Web Push (VAPID). |
| `pdf-viewer.js` · `recorder.min.js` · `encoderWorker.min.js` · `webm-opus-to-ogg.js` | Visor PDF, notas de voz (opus). |
| `tom-select.*` · `emojis.js` · `shortcuts.js` · `txt-ellipsis.js` | Selects enriquecidos, emojis, atajos, elipsis. |

### PWA
`manifest.json`/`manifest.php`, `sw.js` (network-first; ignora peticiones `embed=1`), instalable, splash screens, push. `pwa-reset.php` fuerza limpieza de SW/caché en el cliente.

---

## Módulos

| Módulo | Descripción |
|--------|-------------|
| **auth** | Login (usuario o email), logout, reseteo de contraseña. |
| **dashboard** | Panel con indicadores del negocio. |
| **clientes** | Empresas/clientes (CRM): ficha, acta de alcance, pendientes, ownership por ejecutivo, portal. |
| **cotizaciones** | Cotizaciones/propuestas, PDF (dompdf) multi-marca (GGI/Acreditta), aceptación → certificados+pagos, estados (enviada/aceptada/rechazada/vencida/**desistida**), selección de ejecutivo. |
| **certificados** | Certificados ISO: creación, vigencia por norma, PDF ES/EN, verificación pública condicionada al pago total. |
| **pagos** | Registro y validación de pagos; dispara bonos al pago total. |
| **facturas** | Facturación electrónica SUNAT vía Alegra (factura + nota de crédito), envío al cliente por WhatsApp. |
| **envios** | Envíos y couriers: Olva/Shalom/Serpost (sedes, tracking, export masivo .xlsx), kanban, estados. |
| **inbox** | Bandeja WhatsApp multiagente: hilos, notas internas, respuestas rápidas, copiloto IA, acceso por número. |
| **whatsapp** | Configuración de números (Meta Cloud API / coexistencia), bot de recepción de leads, campañas masivas, plantillas, reporte de anuncios (CTWA). |
| **bonos** | Comisiones por ejecutivo (mensual + historial re-descargable). |
| **metas** | Metas comerciales por ejecutivo. |
| **planilla** | Pago de sueldos por período (base+bonos−descuentos). Activable por `MODULO_PLANILLA_ACTIVO`. |
| **auditorias** | Auditorías de certificación próximas. |
| **documentos / legal** | Documentación de clientes y legal. |
| **llamadas** | Llamadas programadas con recordatorio push. |
| **comunicaciones** | Comunicaciones con el cliente. |
| **reportes** | Reportes (incl. gasto en Meta Ads, conversiones). |
| **historial** | Historial/auditoría (solo gerente_general). |
| **notificaciones** | Centro de notificaciones + log de envíos WhatsApp (reintento fiel). |
| **cuenta** | Perfil del usuario. |
| **mantenimiento** | Catálogos/configuración (normas, estados de envío, agencias, etc.). |

**Áreas públicas** (fuera de `modules/`): `portal/` (portal del cliente por enlace con token), `verificar/` (verificador público de certificados).

---

## Base de datos

**66 tablas/vistas.** Agrupadas por dominio:

- **Usuarios y seguridad**: `usuarios`, `roles`, `rol_permisos`, `push_subscriptions`, `log_actividad`, `secuencias`.
- **CRM / comercial**: `clientes`, `cliente_isos`, `cotizaciones`, `cotizacion_normas`, `cotizacion_archivos`, `cotizacion_seguimientos`, `cotizaciones_historial`, `comunicaciones_cliente`, `historial_responsables_cliente`.
- **Certificados y auditorías**: `certificados`, `normas_iso`, `casas_certificadoras`, `auditorias`, `acta_alcance_cambios`, vistas `v_certificados_por_vencer`, `v_auditorias_proximas`.
- **Pagos y facturación**: `pagos_cliente`, `facturas_cliente`, `notas_credito_cliente`.
- **Envíos**: `envios`, `envios_certificados`, `estados_envio`, `agencias_envio`, vista `v_envios_pendientes`, `feriados`.
- **WhatsApp / inbox**: `wa_numeros`, `wa_numero_usuarios`, `wa_conversaciones`, `wa_mensajes`, `wa_conversacion_etiquetas`, `wa_conversacion_notas`, `wa_etiquetas`, `wa_respuestas_rapidas`, `wa_llamadas`, `wa_asignaciones_log`, `wa_bot_*` (nodos/botones/reglas/triggers/eventos), `wa_campana_destinatarios`, `whatsapp_campanas`, `whatsapp_inbound`, `whatsapp_log`, `whatsapp_templates`.
- **Notificaciones**: `notificaciones`, `notification_events`, `notification_rules`, `notification_history`, `anuncios_vistos`.
- **RRHH / metas**: `bonos_config`, `bonos_ejecutivos`, `bonos_informes`, `metas_ejecutivos`, `planilla`, `planilla_config`.
- **Varios**: `configuracion`, `documentos_cliente`, `llamadas_programadas`, `alertas_email` (archivado).

### Migraciones (`sql/`)
Scripts ordenados cronológicamente, desde `01_tablas_datos.sql` / `02_triggers_vistas.sql` hasta las fechadas `2026-*`. `ggi_sistema.sql` es el dump base. Para un repo nuevo, importar el dump base y luego aplicar las migraciones en orden.

---

## Integraciones externas

| Integración | Para qué | Config (.env) |
|-------------|----------|---------------|
| **WhatsApp — Meta Cloud API** (o Kapso como proxy) | Envío/recepción de mensajes, webhooks, coexistencia, Calling API | `WA_PROVIDER`, `META_WA_*`, `KAPSO_*`, `WA_WEBHOOK_VERIFY_TOKEN`, `META_APP_SECRET` |
| **Alegra → SUNAT** | Facturas electrónicas y notas de crédito | `ALEGRA_EMAIL`, `ALEGRA_TOKEN`, `ALEGRA_STAMP`, `ALEGRA_NC_STAMP`, `ALEGRA_DETRACCION_CUENTA` |
| **Meta Conversions API** | Atribuir cierres de venta a Meta Ads (ctwa_clid) | `META_DATASET_ID`, `META_CAPI_ACCESS_TOKEN` |
| **Meta Marketing API** | Reporte de gasto/rendimiento de anuncios | `META_ADS_TOKEN`, `META_AD_ACCOUNT_ID` |
| **Couriers** | Sedes y tracking: Olva / Shalom / Serpost | `OLVA_TRACKING_API_KEY` |
| **API Perú (RUC/SUNAT)** | Validación de RUC y razón social | `SUNAT_API_TOKEN` |
| **Copiloto IA (inbox)** | Sugerir respuestas | `IA_PROVIDER` (`claude`/`openai`/`gemini`), `IA_ANTHROPIC_KEY`, `IA_OPENAI_KEY`, `IA_GEMINI_KEY(_2)` |
| **Web Push (VAPID)** | Notificaciones a navegador/móvil | `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` |

---

## Tareas programadas (cron)

Scripts en `cron/` (invocables por CLI; por web requieren `?token=CRON_TOKEN`):

| Script | Función |
|--------|---------|
| `notificaciones.php` | Procesa y despacha notificaciones pendientes. |
| `recordatorio_certificados.php` | Avisa (push) antes del vencimiento de certificados. |
| `recordatorio_cotizacion_vencer.php` | Avisa al cliente 3 y 1 día antes de vencer su cotización. |
| `recordatorio_datos_envio.php` | Pide datos de envío tras el pago total. |
| `recordatorio_llamadas.php` | Recordatorio de llamadas programadas (previo + a la hora). |
| `facturas_sunat_recheck.php` | Reconsulta a Alegra el estado SUNAT (async STAMPED → ACCEPTED). |
| `sync_tracking_olva.php` | Sincroniza estados de tracking de Olva. |
| `sugerir_no_enviado.php` | Sugiere marcar "No enviado" tras recordatorios agotados. |
| `wa_campanas.php` | Procesa lotes de campañas de WhatsApp. |
| `limpiar_cache_pdf.php` | Limpia la caché de PDFs en `storage/`. |

> El crontab lo gestiona DirectAdmin. El servidor corre en horario distinto a Lima (verificar zona al programar). Reconstruir el crontab de forma atómica para no perder entradas.

---

## Configuración y variables de entorno

`config/config.php` **no contiene secretos**: carga un archivo `.env` ubicado **fuera de `public_html`** (`../../.env`). Plantilla recomendada (`.env.example`):

```dotenv
# ── Aplicación ──
APP_URL=https://sistema.tu-dominio.com
APP_ENV=production            # production | development

# ── Base de datos ──
DB_HOST=localhost
DB_NAME=
DB_USER=
DB_PASS=
DB_CHARSET=utf8mb4

# ── Seguridad / cron / despliegue ──
CRON_TOKEN=                   # token para disparar crons por web
DEPLOY_SECRET=                # HMAC del webhook de GitHub (deploy.php)

# ── Web Push (VAPID) ──
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=

# ── WhatsApp (Meta Cloud API / Kapso) ──
WA_PROVIDER=meta              # meta | kapso
META_WA_VERSION=v21.0
META_WA_PHONE_NUMBER_ID=
META_WA_TOKEN=
META_WA_WABA_ID=
META_WA_REGISTER_PIN=
META_APP_SECRET=
META_APP_ID=
WA_WEBHOOK_VERIFY_TOKEN=
KAPSO_API_KEY=
KAPSO_PHONE_NUMBER_ID=

# ── Meta Ads (conversiones + gasto) ──
META_DATASET_ID=
META_CAPI_ACCESS_TOKEN=
META_ADS_TOKEN=
META_AD_ACCOUNT_ID=

# ── Facturación Alegra / SUNAT ──
ALEGRA_EMAIL=
ALEGRA_TOKEN=
ALEGRA_STAMP=0                # 0 = borrador (prueba) · 1 = emisión real
ALEGRA_NC_STAMP=0             # notas de crédito: 0 borrador · 1 real
ALEGRA_DETRACCION_CUENTA=

# ── APIs Perú / couriers ──
SUNAT_API_TOKEN=
OLVA_TRACKING_API_KEY=

# ── Copiloto IA (opcional) ──
IA_PROVIDER=claude            # claude | openai | gemini | auto | "gemini,claude,openai"
IA_ANTHROPIC_KEY=
IA_ANTHROPIC_MODEL=claude-sonnet-4-5-20250929
IA_OPENAI_KEY=
IA_OPENAI_MODEL=gpt-4o
IA_GEMINI_KEY=
IA_GEMINI_KEY_2=
IA_GEMINI_MODEL=gemini-flash-latest
```

> **Nunca** versionar el `.env` real ni credenciales. `config/email_alerta.json` es configuración de runtime editable desde la UI y tampoco se versiona.

---

## Instalación y puesta en marcha

Requisitos: PHP 8.3 (extensiones PDO MySQL, cURL, GD, mbstring, openssl), MySQL/MariaDB, Composer, servidor con `mod_rewrite` (Apache) o equivalente LiteSpeed.

```bash
# 1. Clonar
git clone git@github.com:<org>/<repo>.git
cd <repo>

# 2. Dependencias PHP
composer install

# 3. Variables de entorno: crear el .env FUERA de la raíz web
#    (config.php lo busca en ../../.env respecto a config/)
cp .env.example ../../.env   # y completar valores

# 4. Base de datos
mysql -u <user> -p <db> < sql/ggi_sistema.sql
#    luego aplicar las migraciones en orden cronológico:
for f in sql/01_*.sql sql/02_*.sql sql/0*_*.sql sql/1*_*.sql sql/2026-*.sql; do
  mysql -u <user> -p <db> < "$f"
done

# 5. Permisos de escritura (runtime)
mkdir -p storage logs uploads documentaciones_clientes
chmod -R 775 storage logs uploads documentaciones_clientes

# 6. Cron (DirectAdmin / crontab) — ejemplo
# */5 * * * * php /ruta/public_html/cron/notificaciones.php
# (ver tabla "Tareas programadas" para el resto)
```

Configurar también:
- **Webhook de WhatsApp** (Meta): URL `.../modules/whatsapp/webhook_meta.php`, verify token = `WA_WEBHOOK_VERIFY_TOKEN`.
- **Webhook de despliegue** (GitHub): URL `.../deploy.php`, secret = `DEPLOY_SECRET`.
- **PWA / HTTPS**: el sitio debe servirse por HTTPS (cookies `secure`, Service Worker, push).

---

## Despliegue

- **Automático**: `deploy.php` es un webhook. En cada push a `main`, GitHub lo invoca; verifica la firma HMAC (`DEPLOY_SECRET`) y hace `git fetch` + `git reset --hard origin/main` para dejar el servidor idéntico a `main`.
- **`.user.ini`**: ajustes PHP por directorio (sesión de 30 días, `opcache.revalidate_freq=60`).
- **`.htaccess`**: URLs limpias/rewrites, cabeceras de seguridad (CSP, HSTS, X-Frame-Options, `Permissions-Policy` = `(self)` para mic/cámara), compresión y caché de assets.
- **Cache-busting**: los assets se enlazan con `?v=filemtime`; el `.htaccess` marca assets como `immutable`.

---

## Convenciones para mantenedores

- **PHP nativo, sin framework**: cada página es un controlador; la lógica reutilizable va a `core/`.
- **PDO siempre preparado**; nunca concatenar SQL.
- **CSRF obligatorio** en todo POST (`csrf_verify()`); endpoints AJAX devuelven JSON con `json_ok()/json_error()`.
- **Permisos**: puerta de UI (`can()`/`has_role()` en la vista) **y** puerta de servidor (en el endpoint). Mantener ambas en sync.
- **Design system canónico**: usar tokens y clases globales; no redefinir estilos por módulo.
- **SPA**: páginas con estado propio (inbox, etc.) usan `data-spa-nocache` y limpian sus pollers/listeners al re-montarse.
- **Secretos solo en `.env`** (fuera de `public_html`).
- **Zona horaria**: la app usa `America/Lima`; el servidor puede estar en otra zona (cuidar los crons).

---

© Group Global ISO S.A.C. — Software interno propietario.
