// ============================================================
//  GGI SISTEMA — Validación client-side reutilizable
//  assets/js/validacion.js
//
//  Uso:
//    const v = new GGIForm('id-del-form');
//    v.required('campo', 'Mensaje de error');
//    v.minLength('campo', 8, 'Mínimo 8 caracteres');
//    v.email('campo', 'Email inválido');
//    v.ruc('campo', 'RUC inválido');
//    v.selectRequired('campo', 'Selecciona una opción');
//    v.init();   ← activa la validación al hacer submit
// ============================================================

class GGIForm {
    constructor(formId) {
        this.form   = document.getElementById(formId);
        this.rules  = [];   // [{campo, fn, msg}]
        this.groups = {};   // campo -> div.err-msg element
        if (!this.form) console.warn('GGIForm: form #' + formId + ' no encontrado');
    }

    // ── Helpers de UI ────────────────────────────────────────

    _getField(name) {
        return this.form.querySelector('[name="' + name + '"]');
    }

    _getOrCreateErrMsg(campo) {
        if (this.groups[campo]) return this.groups[campo];
        const field = this._getField(campo);
        if (!field) return null;

        // Buscar div.err-msg ya existente (generado por PHP) justo después del campo
        let errDiv = field.parentElement.querySelector('.err-msg');
        if (!errDiv) {
            // Crear uno nuevo si no existe
            errDiv = document.createElement('div');
            errDiv.className = 'err-msg';
            errDiv.style.fontSize = '11px';
            errDiv.style.color = 'var(--red, #ef4444)';
            errDiv.style.marginTop = '3px';
            field.parentElement.appendChild(errDiv);
        }
        this.groups[campo] = errDiv;
        return errDiv;
    }

    _setError(campo, msg) {
        const field  = this._getField(campo);
        const errDiv = this._getOrCreateErrMsg(campo);
        if (field)  { field.classList.add('error'); field.setAttribute('aria-invalid', 'true'); }
        if (errDiv) { errDiv.textContent = msg; errDiv.style.display = 'block'; }
    }

    _clearError(campo) {
        const field  = this._getField(campo);
        const errDiv = this.groups[campo] || (field && field.parentElement.querySelector('.err-msg'));
        if (field)  { field.classList.remove('error'); field.removeAttribute('aria-invalid'); }
        if (errDiv) { errDiv.textContent = ''; errDiv.style.display = 'none'; }
    }

    _clearAll() {
        this.rules.forEach(r => this._clearError(r.campo));
    }

    // ── Definición de reglas ─────────────────────────────────

    required(campo, msg) {
        msg = msg || 'Este campo es requerido.';
        this.rules.push({ campo, fn: (val) => val.trim().length > 0, msg });
        return this;
    }

    minLength(campo, min, msg) {
        msg = msg || 'Mínimo ' + min + ' caracteres.';
        this.rules.push({ campo, fn: (val) => val.trim().length === 0 || val.trim().length >= min, msg });
        return this;
    }

    maxLength(campo, max, msg) {
        msg = msg || 'Máximo ' + max + ' caracteres.';
        this.rules.push({ campo, fn: (val) => val.trim().length <= max, msg });
        return this;
    }

    email(campo, msg) {
        msg = msg || 'Ingresa un email válido.';
        this.rules.push({ campo, fn: (val) => val.trim().length === 0 || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val.trim()), msg });
        return this;
    }

    ruc(campo, msg) {
        msg = msg || 'RUC debe tener 11 dígitos numéricos.';
        this.rules.push({
            campo,
            fn: (val) => {
                val = val.trim();
                if (val.length === 0) return true; // required lo maneja
                if (!/^\d{11}$/.test(val)) return false;
                // Algoritmo dígito verificador RUC Perú
                const factor = [5,4,3,2,7,6,5,4,3,2];
                let suma = 0;
                for (let i = 0; i < 10; i++) suma += parseInt(val[i]) * factor[i];
                let digito = 11 - (suma % 11);
                if (digito >= 10) digito -= 10;
                return parseInt(val[10]) === digito;
            },
            msg
        });
        return this;
    }

    selectRequired(campo, msg) {
        msg = msg || 'Selecciona una opción.';
        this.rules.push({ campo, fn: (val) => val.trim() !== '' && val.trim() !== '0', msg });
        return this;
    }

    date(campo, msg) {
        msg = msg || 'Ingresa una fecha válida.';
        this.rules.push({ campo, fn: (val) => val.trim().length === 0 || /^\d{4}-\d{2}-\d{2}$/.test(val.trim()), msg });
        return this;
    }

    dateRequired(campo, msg) {
        msg = msg || 'La fecha es requerida.';
        this.rules.push({ campo, fn: (val) => /^\d{4}-\d{2}-\d{2}$/.test(val.trim()), msg });
        return this;
    }

    custom(campo, fn, msg) {
        this.rules.push({ campo, fn, msg });
        return this;
    }

    // ── Validar ahora (sin submit) ───────────────────────────

    validate() {
        this._clearAll();
        let ok = true;
        const seen = {};

        for (const rule of this.rules) {
            if (seen[rule.campo]) continue; // solo mostrar primer error por campo
            const field = this._getField(rule.campo);
            if (!field) continue;
            const val = field.value || '';
            if (!rule.fn(val)) {
                this._setError(rule.campo, rule.msg);
                seen[rule.campo] = true;
                ok = false;
            }
        }

        // Enfocar primer campo con error
        if (!ok) {
            const firstError = Object.keys(seen)[0];
            const el = this._getField(firstError);
            if (el) { el.focus(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }

        return ok;
    }

    // ── Activar en el form ───────────────────────────────────

    init() {
        if (!this.form) return this;

        // Limpiar error de un campo cuando el usuario empieza a escribir
        this.rules.forEach(r => {
            const field = this._getField(r.campo);
            if (!field) return;
            const ev = (field.tagName === 'SELECT') ? 'change' : 'input';
            field.addEventListener(ev, () => this._clearError(r.campo));
        });

        // Intercept submit
        this.form.addEventListener('submit', (e) => {
            if (!this.validate()) {
                e.preventDefault();
                e.stopPropagation();
            } else {
                // Deshabilitar botón submit para evitar doble envío
                const btn = this.form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    const original = btn.innerHTML;
                    btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:7px;">'
                        + '<span style="width:13px;height:13px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:spin 0.6s linear infinite;display:inline-block;"></span>'
                        + 'Guardando...</span>';
                    // Restaurar si hay error del servidor (recarga)
                    setTimeout(() => { btn.disabled = false; btn.innerHTML = original; }, 8000);
                }
            }
        });

        return this;
    }
}
