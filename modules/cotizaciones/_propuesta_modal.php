<?php
/**
 * _propuesta_modal.php — Controlador JS único para abrir el formulario de propuesta
 * (CREATE / EDIT / DUPLICATE) dentro del modal reutilizable `ggiOpenForm`.
 *
 * Las tres acciones usan EXACTAMENTE el mismo formulario base (modules/cotizaciones/crear.php)
 * y el mismo host de modal (templates/layout_end.php → ggiOpenForm). Este partial es el
 * único punto que decide la URL/título según el modo y cómo refrescar tras guardar,
 * evitando duplicar esa lógica en index.php y ver.php.
 *
 * Incluir una sola vez por página:
 *   <?php include __DIR__ . '/_propuesta_modal.php'; ?>
 *
 * Uso desde los botones:
 *   onclick="ggiAbrirPropuesta('create')"
 *   onclick="ggiAbrirPropuesta('edit', 123)"
 *   onclick="ggiAbrirPropuesta('duplicate', 123)"
 */
?>
<script>
(function () {
  if (window.ggiAbrirPropuesta) return; // evitar doble definición si se incluye dos veces

  /**
   * Abre el modal unificado en el modo indicado.
   * @param {'create'|'edit'|'duplicate'} modo
   * @param {number} [id]  id de la propuesta origen (edit / duplicate)
   */
  window.ggiAbrirPropuesta = function (modo, id) {
    // Leer window.APP_URL en tiempo de clic (no al cargar): este script se incluye
    // antes de que layout_end.php asigne window.APP_URL, así que calcularlo arriba
    // dejaría BASE = "undefined/...". (Bug orden de scripts → modal mostraba dashboard.)
    var BASE = window.APP_URL + '/modules/cotizaciones/crear.php';
    var url, titulo;
    if (modo === 'edit') {
      url    = BASE + '?edit=' + encodeURIComponent(id);
      titulo = 'Editar propuesta';
    } else if (modo === 'duplicate') {
      url    = BASE + '?duplicar=' + encodeURIComponent(id);
      titulo = 'Duplicar propuesta';
    } else {
      url    = BASE;
      titulo = 'Nueva propuesta';
    }

    if (typeof window.ggiOpenForm !== 'function') {
      // Fallback defensivo: si el host de modal no está disponible, navegar.
      window.location.href = url;
      return;
    }

    window.ggiOpenForm(url, titulo, function (data) {
      window.ggiTrasGuardarPropuesta(modo, data || {});
    });
  };

  /**
   * Acción posterior al guardado exitoso (modal ya cerrado por el host).
   * EDIT → refresca la vista actual en sitio (sin redirigir).
   * CREATE / DUPLICATE → va a la propuesta nueva.
   */
  window.ggiTrasGuardarPropuesta = function (modo, data) {
    var dest;
    if (modo === 'edit') {
      // Confirmación visible al editar (el contenedor de toasts vive en el layout
      // y persiste al refrescar el contenido por SPA).
      if (typeof window.showToast === 'function') {
        window.showToast('Propuesta actualizada correctamente.', 'ok');
      }
      // Refrescar la vista actual (lista o ficha) sin cambiar de pantalla.
      dest = window.location.pathname + window.location.search;
    } else {
      // Nueva propuesta (create o duplicate): ir a su ficha.
      dest = window.APP_URL + '/modules/cotizaciones/ver.php?id=' + (data.id || '') + '&nuevo=1';
    }
    if (typeof window.ggiNavigate === 'function') {
      window.ggiNavigate(dest);
    } else {
      window.location.href = dest;
    }
  };
})();
</script>
