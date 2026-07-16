<?php
/**
 * _empresa_modal.php — Controlador JS para abrir la edición de empresa dentro del
 * modal reutilizable `ggiOpenForm` (mismo patrón que _propuesta_modal.php).
 *
 * Reutiliza el formulario base modules/clientes/editar.php en modo embed.
 *
 * Incluir una sola vez por página:
 *   <?php include __DIR__ . '/_empresa_modal.php'; ?>
 *
 * Uso desde los botones:
 *   onclick="ggiAbrirEmpresa('edit', 123)"
 */
?>
<script>
(function () {
  if (window.ggiAbrirEmpresa) return; // evitar doble definición

  /**
   * Abre el modal unificado para editar una empresa.
   * @param {'edit'} modo
   * @param {number} id  id de la empresa
   */
  window.ggiAbrirEmpresa = function (modo, id) {
    // Leer window.APP_URL en tiempo de clic (se asigna en layout_end.php, después
    // de incluir este script): calcularlo arriba dejaría BASE = "undefined/...".
    var BASE = window.APP_URL + '/modules/clientes/editar.php';
    var url    = BASE + '?id=' + encodeURIComponent(id);
    var titulo = 'Editar empresa';

    if (typeof window.ggiOpenForm !== 'function') {
      // Fallback defensivo: si el host de modal no está disponible, navegar.
      window.location.href = url;
      return;
    }

    window.ggiOpenForm(url, titulo, function (data) {
      window.ggiTrasGuardarEmpresa(modo, data || {});
    });
  };

  /**
   * Acción posterior al guardado exitoso (modal ya cerrado por el host).
   * Refresca la vista actual (ficha o listado) sin cambiar de pantalla.
   */
  window.ggiTrasGuardarEmpresa = function (modo, data) {
    // Confirmación al editar. En borrado NO (ya muestra su propio "Empresa eliminada").
    // El contenedor de toasts vive en el layout y persiste al refrescar por SPA.
    if (!(data && data.deleted) && typeof window.showToast === 'function') {
      window.showToast('Empresa actualizada correctamente.', 'ok');
    }
    var dest = window.location.pathname + window.location.search;
    if (typeof window.ggiNavigate === 'function') {
      window.ggiNavigate(dest);
    } else {
      window.location.href = dest;
    }
  };
})();
</script>
