/**
 * softphone.js — Recibir llamadas de WhatsApp (Calling API) en el navegador.
 *
 * Flujo (Fase A, entrantes):
 *   1. Se sondea el backend (llamada_pendiente); cuando llega un evento "connect"
 *      con el SDP offer del que llama, suena.
 *   2. Al "Contestar": WebRTC toma el micrófono, fija el offer como remoto, crea
 *      el answer, junta los ICE y lo manda a Meta con pre_accept y luego accept.
 *   3. El audio remoto se reproduce; "Colgar" hace terminate.
 *
 * Requiere: window.ggiPost (CSRF) y que la página resuelva 'ajax.php' al del inbox.
 */
(function () {
  'use strict';
  if (!window.RTCPeerConnection) return;   // navegador sin WebRTC

  var AJAX = 'ajax.php';
  var pc = null, micStream = null, actual = null, sonando = null, tempo = null, manejados = {};

  // ── Tono de llamada (Web Audio, sin archivos externos) ─────────────────────
  var ringCtx = null, ringTimer = null;
  function ringStart() {
    ringStop();
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      ringCtx = new AC();
      var tocar = function () {
        if (!ringCtx) return;
        // Doble bip tipo timbre: dos tonos cortos
        [0, 0.4].forEach(function (off) {
          var o = ringCtx.createOscillator(), g = ringCtx.createGain();
          o.type = 'sine'; o.frequency.value = off ? 480 : 440;
          o.connect(g); g.connect(ringCtx.destination);
          var t = ringCtx.currentTime + off;
          g.gain.setValueAtTime(0.0001, t);
          g.gain.exponentialRampToValueAtTime(0.25, t + 0.03);
          g.gain.exponentialRampToValueAtTime(0.0001, t + 0.32);
          o.start(t); o.stop(t + 0.34);
        });
      };
      tocar();
      ringTimer = setInterval(tocar, 2600);   // patrón de timbre cada 2.6 s
    } catch (_) {}
  }
  function ringStop() {
    if (ringTimer) { clearInterval(ringTimer); ringTimer = null; }
    if (ringCtx) { try { ringCtx.close(); } catch (_) {} ringCtx = null; }
  }

  function post(datos) {
    if (window.ggiPost) return window.ggiPost(AJAX, datos);
    var fd = new FormData();
    Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
    fd.append('_csrf', window.CSRF_TOKEN || '');
    return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  // ── Sondeo de llamada entrante ─────────────────────────────────────────────
  function sondear() {
    if (actual) return;   // ya hay una llamada en curso
    fetch(AJAX + '?accion=llamada_pendiente', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var ll = (j && j.ok && j.llamada) ? j.llamada : null;
        // Si estábamos sonando y esa llamada ya NO está pendiente (el que llama
        // colgó, o se rechazó), cancelar el timbre y quitar el panel.
        if (sonando && (!ll || ll.call_id !== sonando.call_id)) { sonando = null; quitarUI(); }
        if (!ll) return;
        if (manejados[ll.call_id]) return;                 // ya rechazada/atendida/terminada
        if (sonando && sonando.call_id === ll.call_id) return;  // ya sonando
        if (!ll.sdp) return;                               // sin offer no se puede contestar
        sonando = ll;
        pintarEntrante(ll);
      })
      .catch(function () {});
  }

  // ── UI: timbre entrante ────────────────────────────────────────────────────
  function pintarEntrante(ll) {
    quitarUI();
    var box = document.createElement('div');
    box.className = 'sf-pop sf-ring';
    box.id = 'sf-pop';
    box.innerHTML =
        '<div class="sf-head"><span class="sf-dot"></span>Llamada entrante</div>'
      + '<div class="sf-nombre">' + esc(ll.nombre || ('+' + ll.telefono)) + '</div>'
      + '<div class="sf-sub">WhatsApp · llamada de voz</div>'
      + '<div class="sf-acts">'
      +   '<button type="button" class="sf-btn sf-rechazar" id="sf-rechazar">Rechazar</button>'
      +   '<button type="button" class="sf-btn sf-contestar" id="sf-contestar">Contestar</button>'
      + '</div>';
    document.body.appendChild(box);
    document.getElementById('sf-contestar').addEventListener('click', function () { contestar(ll); });
    document.getElementById('sf-rechazar').addEventListener('click', function () { rechazar(ll); });
    ringStart();
  }

  var vigTimer = null;
  function vigilarFin(ll) {
    if (vigTimer) clearInterval(vigTimer);
    vigTimer = setInterval(function () {
      fetch(AJAX + '?accion=llamada_estado&call_id=' + encodeURIComponent(ll.call_id),
            { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && j.terminada) { finalizar(); } })
        .catch(function () {});
    }, 2500);
  }

  function pintarEnCurso(ll) {
    quitarUI();
    vigilarFin(ll);   // si el otro cuelga, el webhook lo marca y colgamos solos
    var box = document.createElement('div');
    box.className = 'sf-pop sf-live';
    box.id = 'sf-pop';
    box.innerHTML =
        '<div class="sf-head"><span class="sf-dot viva"></span>En llamada</div>'
      + '<div class="sf-nombre">' + esc(ll.nombre || ('+' + ll.telefono)) + '</div>'
      + '<div class="sf-sub" id="sf-cron">00:00</div>'
      + '<div class="sf-acts">'
      +   '<button type="button" class="sf-btn sf-colgar" id="sf-colgar">Colgar</button>'
      + '</div>';
    document.body.appendChild(box);
    document.getElementById('sf-colgar').addEventListener('click', function () { colgar(ll); });
    var t0 = Date.now();
    tempo = setInterval(function () {
      var s = Math.floor((Date.now() - t0) / 1000), el = document.getElementById('sf-cron');
      if (el) el.textContent = ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2);
    }, 500);
  }

  function quitarUI() {
    var p = document.getElementById('sf-pop'); if (p) p.remove();
    if (tempo) { clearInterval(tempo); tempo = null; }
    ringStop();
  }

  // ── Contestar: WebRTC + pre_accept + accept ────────────────────────────────
  function contestar(ll) {
    actual = ll; sonando = null; ringStop();
    var cont = document.getElementById('sf-contestar');
    if (cont) { cont.disabled = true; cont.textContent = 'Conectando…'; }

    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      micStream = stream;
      pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });

      pc.ontrack = function (e) {
        var a = document.getElementById('sf-audio');
        if (!a) { a = document.createElement('audio'); a.id = 'sf-audio'; a.autoplay = true; document.body.appendChild(a); }
        a.srcObject = e.streams[0];
      };
      pc.onconnectionstatechange = function () {
        if (pc && (pc.connectionState === 'failed' || pc.connectionState === 'disconnected')) finalizar();
      };
      stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });

      return pc.setRemoteDescription({ type: 'offer', sdp: ll.sdp })
        .then(function () { return pc.createAnswer(); })
        .then(function (ans) { return pc.setLocalDescription(ans); })
        .then(esperarIce)
        .then(function () {
          var sdp = pc.localDescription.sdp;
          // Meta exige pre_accept y LUEGO accept, con el mismo SDP answer.
          return post({ accion: 'llamada_accion', call_id: ll.call_id, phone_number_id: ll.phone_number_id, accion_llamada: 'pre_accept', sdp: sdp })
            .then(function () {
              return post({ accion: 'llamada_accion', call_id: ll.call_id, phone_number_id: ll.phone_number_id, accion_llamada: 'accept', sdp: sdp });
            });
        })
        .then(function (j) {
          if (j && j.ok) { pintarEnCurso(ll); }
          else { toast(j && j.msg ? j.msg : 'No se pudo contestar la llamada'); finalizar(); }
        });
    }).catch(function (err) {
      toast(err && err.name === 'NotAllowedError'
        ? 'Micrófono bloqueado. Actívalo para contestar llamadas.'
        : 'No se pudo contestar (' + ((err && err.name) || 'error') + ')');
      finalizar();
    });
  }

  // Espera a que WebRTC termine de juntar candidatos ICE (envío no-trickle).
  function esperarIce() {
    return new Promise(function (resolve) {
      if (pc.iceGatheringState === 'complete') return resolve();
      var done = false;
      var fin = function () { if (done) return; done = true; resolve(); };
      pc.addEventListener('icegatheringstatechange', function () {
        if (pc.iceGatheringState === 'complete') fin();
      });
      setTimeout(fin, 2500);   // tope: no esperar indefinidamente
    });
  }

  function rechazar(ll) {
    manejados[ll.call_id] = true;   // que el sondeo no la vuelva a hacer sonar
    sonando = null; ringStop();
    post({ accion: 'llamada_accion', call_id: ll.call_id, phone_number_id: ll.phone_number_id, accion_llamada: 'reject' });
    quitarUI();
  }

  function colgar(ll) {
    manejados[ll.call_id] = true;
    post({ accion: 'llamada_accion', call_id: ll.call_id, phone_number_id: ll.phone_number_id, accion_llamada: 'terminate' });
    finalizar();
  }

  function finalizar() {
    if (vigTimer) { clearInterval(vigTimer); vigTimer = null; }
    if (pc) { try { pc.close(); } catch (_) {} pc = null; }
    if (micStream) { micStream.getTracks().forEach(function (t) { t.stop(); }); micStream = null; }
    var a = document.getElementById('sf-audio'); if (a) a.remove();
    actual = null; sonando = null;
    quitarUI();
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function toast(m) { if (window.showToast) showToast(m, 'error'); else console.warn('[softphone]', m); }

  // Arranca el sondeo cuando la pestaña está visible.
  function iniciar() {
    sondear();
    setInterval(function () { if (!document.hidden) sondear(); }, 3000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
