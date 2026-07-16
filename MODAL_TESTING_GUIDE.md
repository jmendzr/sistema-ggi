# Guía de Testing: Migración de Modales a Clases Estándar GGI

## Resumen del Cambio

Se ha migrado **10 modales** en **5 módulos** desde estilos inline y clases antiguas (`modal-overlay`, `modal-box`, etc.) a las **nuevas clases estándar GGI** (`ggi-modal-overlay`, `ggi-modal-box`, `ggi-modal-header`, etc.) definidas en `assets/css/design-system.css`.

### Módulos Migratos

| Módulo | Modales | IDs |
|--------|---------|-----|
| **alertas/index.php** | 1 | `#modal-exec` |
| **cotizaciones/index.php** | 3 | `#modal-rechazo`, `#modal-seguimiento`, `#modal-aceptar` |
| **cotizaciones/ver.php** | 2 | `#modal-seguimiento`, `#modal-wa-confirm` |
| **facturas/_seccion.php** | 1 | `#modal-factura` |
| **mantenimiento/roles.php** | 3 | `#modal-crear-rol`, `#modal-del-rol`, `#modal-rol` |

---

## Pruebas de Consistencia Visual

### 1. Comparar con Modales Existentes (GGIConfirm)

**Objetivo:** Verificar que los nuevos modales tienen la misma apariencia que los confirmadores estándar.

**Modales de referencia (ya en estándar):**
- Acta de Alcance (modules/clientes/ver.php) - confirmación de cambios
- Certificados (modules/certificados/editar.php) - eliminar
- Clientes (modules/clientes/editar.php) - eliminar

**Prueba:**

1. Abre **modules/clientes/ver.php** → busca "Acta de Alcance" → haz clic en "Cambios solicitados"
   - Observa: Bordes, colores, padding, botones
   
2. Abre **modules/certificados/editar.php** → scroll a la tabla de certificados → haz clic en la papelera
   - Observa: Estilo del modal

3. Abre **modules/cotizaciones/index.php** → tabla → haz clic en "Rechazar"
   - Compara `#modal-rechazo` con los modales de referencia
   - **Deben verse idénticos** en colores, bordes y espacios

---

## Pruebas Funcionales por Módulo

### 2. **alertas/index.php** - Modal de ejecución

**ID:** `#modal-exec`

**Pasos:**

1. Navega a **Alertas** (mantenimiento → Alertas de certificados)
2. Localiza el botón **"Ejecutar ahora"**
3. Haz clic → El modal debe abrirse con:
   - ✅ Título "Ejecución completada" con icono ✓ verde
   - ✅ Log de ejecución en el `<pre>` (fondo oscuro, texto cian)
   - ✅ Botón "Cerrar" en el footer
4. Cierra el modal de 3 formas:
   - ✅ Haciendo clic en el botón "Cerrar"
   - ✅ Haciendo clic en la X (esquina superior derecha)
   - ✅ Haciendo clic fuera del modal (overlay)
5. Verifica que no haya errores en la consola (F12)

**Validación:**
- [ ] Modal abre/cierra correctamente
- [ ] Estilos visuales son profesionales
- [ ] El scroll del log funciona si hay mucho contenido
- [ ] Responsive en móvil (viewport 375px)

---

### 3. **cotizaciones/index.php** - 3 Modales

#### 3.1 Modal Rechazar (`#modal-rechazo`)

**Pasos:**

1. Navega a **Cotizaciones** → tabla de propuestas
2. Ubica una propuesta → haz clic en **"Rechazar"**
3. El modal debe mostrar:
   - ✅ Título: "¿Por qué se rechaza?"
   - ✅ Texto descriptivo
   - ✅ Textarea para motivo
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Confirmar rechazo" (rojo/naranja)

**Validación:**
- [ ] Puedo escribir en el textarea
- [ ] Botón "Cancelar" cierra sin guardar
- [ ] Botón "Confirmar rechazo" envía (observa el toast)
- [ ] El modal se ve igual que `#modal-rechazo` de **clientes/ver.php**

#### 3.2 Modal Seguimiento Rápido (`#modal-seguimiento`)

**Pasos:**

1. Misma tabla → haz clic en **"Seguimiento"** (icono con reloj)
2. El modal debe mostrar:
   - ✅ Título: "Seguimiento rápido"
   - ✅ Subtítulo con datos de la propuesta
   - ✅ Grid de 2 columnas: "Tipo" y "Resultado"
   - ✅ Textarea de "Notas"
   - ✅ Botones "Cancelar" y "Guardar seguimiento"

**Validación:**
- [ ] Los selects funcionan (abren opciones)
- [ ] El textarea es editable
- [ ] Los botones funcionan
- [ ] El layout es grid 2 columnas en desktop
- [ ] En móvil se apila a 1 columna

#### 3.3 Modal Aceptar (`#modal-aceptar`)

**Pasos:**

1. Tabla → haz clic en **"Aceptar"** (botón verde)
2. Modal debe mostrar:
   - ✅ Título: "¿Aceptar esta propuesta?"
   - ✅ Advertencia: "Se creará el cliente y certificados..."
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Confirmar aceptación" (verde)

**Validación:**
- [ ] Texto de advertencia es visible
- [ ] Botones funcionan correctamente
- [ ] Al aceptar, la propuesta cambia de estado

---

### 4. **cotizaciones/ver.php** - 2 Modales

#### 4.1 Modal Seguimiento Detallado (`#modal-seguimiento`)

**Pasos:**

1. Navega a **Cotizaciones** → tabla → haz clic en una propuesta (nombre)
2. En la página de detalle, busca botón **"Registrar seguimiento"** (o similar)
3. Modal debe mostrar:
   - ✅ Icono de reloj en el título
   - ✅ Grid 2 columnas: "Tipo de contacto" + "Resultado"
   - ✅ Textarea de "Notas del seguimiento"
   - ✅ Campo de fecha "Próxima acción" (opcional)

**Validación:**
- [ ] Modal abre sin errores
- [ ] Selects tienen opciones (Llamada, Email, WhatsApp, etc.)
- [ ] El textarea es editable
- [ ] Puedo seleccionar una fecha
- [ ] Al guardar, aparece toast de confirmación
- [ ] Formdata enviado correctamente

#### 4.2 Modal WhatsApp (`#modal-wa-confirm`)

**Pasos:**

1. Página de detalle → busca **"Enviar propuesta por WhatsApp"** (o botón similar)
2. Modal debe mostrar:
   - ✅ Ícono de WhatsApp (verde #25D366)
   - ✅ Pregunta: "¿Deseas enviar la propuesta X a Y por WhatsApp?"
   - ✅ Teléfono en un box verde
   - ✅ Botón "Ahora no" (gris)
   - ✅ Botón "Abrir WhatsApp" (verde)

**Validación:**
- [ ] Botón "Ahora no" cierra el modal
- [ ] Botón "Abrir WhatsApp" abre el enlace (puede ser en simulador)
- [ ] El color verde es consistente
- [ ] Modal se ve profesional

---

### 5. **facturas/_seccion.php** - Modal de Carga

**ID:** `#modal-factura`

**Pasos:**

1. Navega a **Facturas** → busca cliente con facturas
2. Localiza botón **"Cargar factura"** (o "Subir factura")
3. El modal debe abrirse con:
   - ✅ Título: "Cargar factura"
   - ✅ Campo: "N° de Factura"
   - ✅ Campo: "Archivo PDF"
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Cargar y enviar por WhatsApp" (naranja)

**Validación:**
- [ ] Modal abre correctamente
- [ ] Puedo escribir en el campo de número
- [ ] Puedo seleccionar un archivo PDF
- [ ] Botón "Cancelar" cierra sin guardar
- [ ] Al enviar, aparece toast de confirmación/error
- [ ] **Crítico:** En la consola (F12), verifica que NO haya errores 404 o de carga

---

### 6. **mantenimiento/roles.php** - 3 Modales

#### 6.1 Modal Crear Rol (`#modal-crear-rol`)

**Pasos:**

1. Navega a **Mantenimiento → Roles**
2. Busca botón **"Crear nuevo rol"** (superior)
3. Modal debe mostrar:
   - ✅ Título: "Nuevo rol"
   - ✅ Campo: "Identificador interno" con preview de slug
   - ✅ Campo: "Etiqueta visible"
   - ✅ Textarea: "Descripción"
   - ✅ Picker de color
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Crear rol" (azul)

**Validación:**
- [ ] Al escribir identificador, el slug preview se actualiza
- [ ] El picker de color funciona
- [ ] Al crear, el modal cierra y el rol aparece en la tabla
- [ ] En dark mode, los campos tienen fondo oscuro

#### 6.2 Modal Eliminar Rol (`#modal-del-rol`)

**Pasos:**

1. En la tabla de roles, busca un rol → haz clic en el icono **papelera**
2. Modal debe mostrar:
   - ✅ Título en rojo: "Eliminar rol"
   - ✅ Icono de basura en rojo
   - ✅ Pregunta: "¿Estás seguro de eliminar el rol X?"
   - ✅ Advertencia: "no se puede deshacer"
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Eliminar" (rojo)

**Validación:**
- [ ] El color rojo es consistente (#ef4444)
- [ ] Botón "Cancelar" cierra sin hacer nada
- [ ] Botón "Eliminar" elimina el rol y actualiza la tabla
- [ ] Toast aparece confirmando la acción

#### 6.3 Modal Editar Rol (`#modal-rol`)

**Pasos:**

1. En la tabla de roles, haz clic en el nombre del rol (o icono editar)
2. Modal debe mostrar:
   - ✅ Título: "Editar rol"
   - ✅ Campo: "Nombre del rol" (deshabilitado/readonly)
   - ✅ Campo: "Etiqueta visible"
   - ✅ Textarea: "Descripción"
   - ✅ Picker de color
   - ✅ Botón "Cancelar" (gris)
   - ✅ Botón "Guardar cambios" (azul)

**Validación:**
- [ ] El nombre está disabled (no puedo editarlo)
- [ ] Puedo cambiar etiqueta, descripción y color
- [ ] Al guardar, los cambios aparecen en la tabla
- [ ] En dark mode, se ve correctamente

---

## Pruebas de Comportamiento

### 7. Cierre de Modales

**Objetivo:** Verificar que todos los modales cierran de la misma forma.

**Para cada modal:**

1. Abre el modal
2. **Prueba cerrar con ESC:**
   - Presiona Escape
   - ✅ El modal debe cerrarse
   
3. **Prueba cerrar con X:**
   - Haz clic en el botón X (esquina superior derecha)
   - ✅ El modal debe cerrarse
   
4. **Prueba cerrar con overlay:**
   - Haz clic fuera del modal (en el fondo oscuro)
   - ✅ El modal debe cerrarse (excepto si tiene un formulario sin guardar, donde debería confirmar primero)

**Nota:** Si un modal tiene un formulario, el comportamiento de ESC puede variar según la implementación.

---

### 8. Responsive Design

**Objetivo:** Verificar que los modales se adapten correctamente a diferentes tamaños de pantalla.

**Prueba en 3 viewports:**

**Desktop (1920px):**
- [ ] Modal centrado
- [ ] Ancho máximo respetado
- [ ] Todos los elementos visible sin scroll

**Tablet (768px):**
- [ ] Modal se adapta
- [ ] Padding se mantiene
- [ ] Botones se ven bien

**Mobile (375px):**
- [ ] Modal ocupa 100% con margins (20px)
- [ ] Contenido no se corta
- [ ] Formularios son usables
- [ ] Botones son grandes (>44px de alto para toque)

**Pasos:**

1. Abre DevTools (F12)
2. Alterna entre dispositivos: `Ctrl+Shift+M` (o `Cmd+Shift+M` en Mac)
3. Elige preset: iPhone 12, iPad, Desktop
4. Abre cada modal en cada viewport
5. Verifica que se vea bien

---

### 9. Comparación de Botones

**Objetivo:** Verificar que los botones estándar se aplican consistentemente.

**Botones esperados:**

| Clase | Color | Uso |
|-------|-------|-----|
| `.ggi-btn-confirm-cancel` | Gris (#f9fafb fondo, borde 1px) | Cancelar |
| `.ggi-btn-confirm-action` | Naranja (#f59e0b) | Guardar/Aceptar/Confirmar |
| Custom (rojo) | Rojo (#ef4444) | Eliminar/Rechazar |

**Prueba:**

1. Captura pantallazos de botones en:
   - Acta de Alcance (módulo Clientes)
   - Cotizaciones/Rechazar
   - Roles/Eliminar
2. Compara colores y tamaños
3. ✅ Deben ser **idénticos**

---

### 10. Validación de Errores en Consola

**Objetivo:** Asegurarse de que no hay errores de JavaScript o CSS.

**Pasos:**

1. Abre DevTools (F12) → pestaña **Console**
2. Navega por todos los módulos y abre cada modal
3. Busca errores (en rojo):
   - ❌ `Uncaught ReferenceError`
   - ❌ `Cannot read property`
   - ❌ `404 Not Found`
   - ❌ `Syntax error`

4. **Si hay errores:**
   - Toma nota del mensaje exacto
   - Indica en qué modal aparece
   - Reporta para corrección

5. **Si no hay errores:**
   - ✅ Aprobado

---

### 11. Dark Mode

**Objetivo:** Verificar que los modales se ven correctamente en tema oscuro.

**Pasos:**

1. En el menú de usuario → **Tema** → selecciona **Oscuro**
2. Abre los modales en los siguientes módulos:
   - Alertas
   - Cotizaciones
   - Facturas
   - Roles

3. Verifica:
   - ✅ Texto legible (suficiente contraste)
   - ✅ Fondo del modal es oscuro
   - ✅ Bordes visibles
   - ✅ Botones visibles
   - ✅ Campos de formulario tienen fondo oscuro

**Validación:**
- [ ] Todos los modales ven bien en dark mode
- [ ] No hay texto blanco sobre fondo blanco
- [ ] No hay texto negro sobre fondo negro

---

## Checklist Final

### Antes de Pasar a Producción

- [ ] Todos los 10 modales abren correctamente
- [ ] Todos los 10 modales cierran correctamente (ESC, X, overlay)
- [ ] Los estilos visuales son consistentes
- [ ] Responsive design en 3 viewports
- [ ] No hay errores en la consola
- [ ] Dark mode funciona en todos
- [ ] Las acciones (rechazar, guardar, crear, eliminar, etc.) funcionan
- [ ] Los toasts de confirmación aparecen
- [ ] Comparación visual con modales estándar: IDÉNTICOS

### Por Módulo

**Alertas:**
- [ ] Modal abre, ejecuta y muestra logs
- [ ] Cierre funciona en 3 formas

**Cotizaciones (index):**
- [ ] Modal rechazar: captura motivo y envía
- [ ] Modal seguimiento: captura datos y envía
- [ ] Modal aceptar: confirma y crea cliente

**Cotizaciones (ver):**
- [ ] Modal seguimiento: formulario completo funciona
- [ ] Modal WhatsApp: abre enlace correctamente

**Facturas:**
- [ ] Modal abre, permite seleccionar PDF
- [ ] Envía correctamente (o muestra error)

**Roles:**
- [ ] Modal crear: valida identificador y crea
- [ ] Modal eliminar: confirma y elimina
- [ ] Modal editar: edita y guarda cambios

---

## Notas de Implementación

### Cambios Técnicos Realizados

1. **HTML Structure:**
   - Cambio: `.modal-overlay` → `.ggi-modal-overlay`
   - Cambio: `.modal-box` → `.ggi-modal-box`
   - Cambio: `.modal-hdr` → `.ggi-modal-header`
   - Cambio: `.modal-title` → `.ggi-modal-title`
   - Cambio: `.modal-close` → `.ggi-modal-close`
   - Cambio: `.modal-body` → `.ggi-modal-body`
   - Cambio: `.modal-footer` → `.ggi-modal-footer`
   - Cambio: `.btn btn-primary btn-sm` → `.ggi-btn-confirm-action`
   - Cambio: `.btn btn-outline btn-sm` → `.ggi-btn-confirm-cancel`

2. **CSS:**
   - Eliminadas definiciones locales de `.modal-*` que conflictúan
   - Ahora usa clases estándar de `assets/css/design-system.css`

3. **JavaScript:**
   - **facturas/_seccion.php:** Cambio de `style.display = 'flex'` a `classList.add('open')`
   - Los demás ya usaban `classList.add('open')` / `remove('open')`

---

## Soporte

Si encuentras problemas durante el testing:

1. Revisa la **Consola (F12)** para errores específicos
2. Compara con un modal funcionando (ej: Acta de Alcance)
3. Verifica que NO haya cambios de CSS inline que sobrescriban las clases
4. Prueba en un navegador diferente (Chrome, Firefox, Safari)

---

**Fecha de migración:** 2026-06-17  
**Clases estándar:** `ggi-modal-*` desde `assets/css/design-system.css`  
**Componentes:** 10 modales en 5 módulos
