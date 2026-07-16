/**
 * GGI Search-Select v3
 * Dropdown: nombre a la izquierda, RUC como chip a la derecha.
 * Highlight del texto buscado. Botón × para limpiar.
 */
function initSearchSelect({ textInputId, dropdownId, hiddenSelectId, data, onSelect }) {
  var inp    = document.getElementById(textInputId);
  var drop   = document.getElementById(dropdownId);
  var hidden = document.getElementById(hiddenSelectId);
  if (!inp || !drop || !hidden) return;

  var activeIdx = -1;
  var clearBtn  = null;

  /* ── Botón limpiar ── */
  var wrap = inp.closest('.ss-wrap');
  if (wrap) {
    clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'ss-clear';
    clearBtn.title = 'Limpiar';
    clearBtn.innerHTML = '<svg width="8" height="8" viewBox="0 0 8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="7" y2="7"/><line x1="7" y1="1" x2="1" y2="7"/></svg>';
    clearBtn.style.display = 'none';
    clearBtn.addEventListener('mousedown', function(e) {
      e.preventDefault();
      inp.value = ''; hidden.value = '';
      hidden.dispatchEvent(new Event('change'));
      clearBtn.style.display = 'none';
      drop.style.display = 'none';
      inp.focus();
    });
    wrap.appendChild(clearBtn);
  }

  function syncClear() {
    if (clearBtn) clearBtn.style.display = (inp.value.trim() || hidden.value) ? 'flex' : 'none';
  }

  /* ── Pre-fill ── */
  if (hidden.value) {
    var pre = data.find(function(c){ return String(c.id) === String(hidden.value); });
    if (pre) { inp.value = pre.label; syncClear(); }
  }

  /* ── Helpers ── */
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function hl(text, q) {
    if (!q || !text) return esc(text);
    var idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx < 0) return esc(text);
    return esc(text.slice(0, idx)) +
      '<mark class="ss-mark">' + esc(text.slice(idx, idx + q.length)) + '</mark>' +
      esc(text.slice(idx + q.length));
  }

  /* ── Render dropdown ── */
  function renderDrop(list, q) {
    activeIdx = -1;
    drop.innerHTML = '';

    if (!list.length) {
      drop.innerHTML =
        '<div class="ss-empty">' +
        '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
        (q ? ' Sin resultados para &ldquo;<b>' + esc(q) + '</b>&rdquo;' : ' Sin empresas registradas') +
        '</div>';
      drop.style.display = 'block';
      return;
    }

    list.slice(0, 60).forEach(function(c, i) {
      var d = document.createElement('div');
      d.className = 'ss-item';
      d.dataset.id    = c.id;
      d.dataset.label = c.label;

      // Extraer nombre limpio (sin el prefijo RUC)
      var nameStr = c.razon_social || (c.ruc ? c.label.replace(c.ruc + ' \u2014 ', '') : c.label);

      var nameHtml = '<span class="ss-name">' + hl(nameStr, q) + '</span>';
      var rucHtml  = c.ruc ? '<span class="ss-ruc">' + hl(c.ruc, q) + '</span>' : '';

      d.innerHTML = nameHtml + rucHtml;

      d.addEventListener('mouseenter', function() { clearActive(); d.classList.add('active'); activeIdx = i; });
      d.addEventListener('mouseleave', function() { d.classList.remove('active'); });
      d.addEventListener('mousedown',  function(e) { e.preventDefault(); selectItem(c.id, c.label); });
      drop.appendChild(d);
    });

    drop.style.display = 'block';
  }

  function clearActive() {
    drop.querySelectorAll('.ss-item.active').forEach(function(el){ el.classList.remove('active'); });
  }

  function selectItem(id, label) {
    inp.value = label;
    drop.style.display = 'none';
    activeIdx = -1;
    if (String(hidden.value) !== String(id)) {
      hidden.value = id;
      hidden.dispatchEvent(new Event('change'));
    }
    syncClear();
    if (typeof onSelect === 'function') onSelect(id, label);
  }

  /* ── Eventos ── */
  function matchQuery(c, q) {
    var ql = q.toLowerCase();
    return c.label.toLowerCase().includes(ql) || (c.ruc && c.ruc.includes(q));
  }

  inp.addEventListener('input', function() {
    var q = inp.value.trim();
    hidden.value = '';
    syncClear();
    if (!q) { drop.style.display = 'none'; return; }
    renderDrop(data.filter(function(c){ return matchQuery(c, q); }), q);
  });

  inp.addEventListener('focus', function() {
    var q = inp.value.trim();
    renderDrop(q ? data.filter(function(c){ return matchQuery(c, q); }) : data, q);
  });

  inp.addEventListener('keydown', function(e) {
    var items = drop.querySelectorAll('.ss-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault(); clearActive();
      activeIdx = Math.min(activeIdx + 1, items.length - 1);
      if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); clearActive();
      activeIdx = Math.max(activeIdx - 1, 0);
      if (items[activeIdx]) { items[activeIdx].classList.add('active'); items[activeIdx].scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'Enter' && activeIdx >= 0 && drop.style.display !== 'none') {
      e.preventDefault();
      var sel = items[activeIdx];
      if (sel) selectItem(sel.dataset.id, sel.dataset.label);
    } else if (e.key === 'Escape') {
      drop.style.display = 'none';
    }
  });

  document.addEventListener('click', function(e) {
    if (!wrap || (!wrap.contains(e.target) && !drop.contains(e.target))) drop.style.display = 'none';
  });
}
