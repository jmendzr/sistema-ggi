/**
 * webm-opus-to-ogg.js — Reempaqueta audio Opus de un contenedor WebM a Ogg.
 *
 * Por qué existe: WhatsApp Cloud API solo acepta audio en OGG con códec Opus,
 * pero Chrome y Chrome Android graban en WebM/Opus. El stream Opus es idéntico
 * en ambos; solo cambia el contenedor. Este código extrae los paquetes Opus
 * del WebM (parser EBML mínimo) y los reescribe en un contenedor Ogg válido,
 * todo en el navegador, sin ffmpeg ni WASM.
 *
 * Safari (mp4/aac) y Firefox (ogg/opus) no lo necesitan: su formato ya sirve.
 *
 * Uso:  const oggBlob = await webmOpusToOgg(webmBlob);
 */
(function (global) {
  'use strict';

  // ── CRC-32 de Ogg (polinomio 0x04c11db7, sin reflejar) ────────────────────
  var CRC = (function () {
    var t = new Uint32Array(256);
    for (var i = 0; i < 256; i++) {
      var r = i << 24;
      for (var j = 0; j < 8; j++) r = (r & 0x80000000) ? ((r << 1) ^ 0x04c11db7) : (r << 1);
      t[i] = r >>> 0;
    }
    return t;
  })();

  function crc32(bytes) {
    var c = 0;
    for (var i = 0; i < bytes.length; i++) c = ((c << 8) ^ CRC[((c >>> 24) ^ bytes[i]) & 0xff]) >>> 0;
    return c >>> 0;
  }

  // ── Lector EBML (WebM/Matroska) ────────────────────────────────────────────
  // Longitud de un VINT según los bits altos del primer byte.
  function vintLen(b) {
    for (var m = 0x80, n = 1; m; m >>= 1, n++) if (b & m) return n;
    return 8;
  }

  // Lee un ID de elemento (mantiene el marcador de longitud).
  function readId(buf, p) {
    var len = vintLen(buf[p]), id = 0;
    for (var i = 0; i < len; i++) id = (id * 256) + buf[p + i];
    return { id: id >>> 0, next: p + len };
  }

  // Lee un tamaño VINT (quita el marcador de longitud).
  // MediaRecorder escribe Segment y Cluster con "tamaño desconocido" (todos los
  // bits de datos en 1) porque graba en streaming; eso se marca con unknown=true
  // para que quien recorre use el final del padre en vez de un tamaño gigante.
  function readSize(buf, p) {
    var len = vintLen(buf[p]);
    var val = buf[p] & (0xff >> len);
    var allOnes = val === (0xff >> len);
    for (var i = 1; i < len; i++) {
      val = (val * 256) + buf[p + i];
      if (buf[p + i] !== 0xff) allOnes = false;
    }
    return { size: val, next: p + len, unknown: allOnes };
  }

  /**
   * Recorre un WebM y devuelve { head, tags, packets, samples }.
   *  - head: bytes de OpusHead (del CodecPrivate del track de audio)
   *  - packets: arreglo de Uint8Array, cada uno un frame Opus
   */
  function parseWebm(buf) {
    var opusHead = null;
    var packets = [];
    var audioTrack = null;

    // Recorre hijos de un elemento maestro entre [start, end).
    function walk(start, end, insideCluster) {
      var p = start;
      while (p < end) {
        var e = readId(buf, p); p = e.next;
        var s = readSize(buf, p); p = s.next;
        var cid = e.id, body = p;
        // Tamaño desconocido (Segment/Cluster en streaming) → hasta el final del padre
        var bodyEnd = s.unknown ? end : Math.min(p + s.size, end);

        // IDs de Matroska usados
        if (cid === 0x18538067 || cid === 0x1654AE6B || cid === 0xAE || cid === 0x1F43B675) {
          // Segment / Tracks / TrackEntry / Cluster → descender
          walk(body, bodyEnd, cid === 0x1F43B675);
        } else if (cid === 0xD7) {            // TrackNumber
          audioTrack = buf[body];             // 1 byte alcanza para el track típico
        } else if (cid === 0x63A2) {          // CodecPrivate (OpusHead)
          opusHead = buf.subarray(body, bodyEnd);
        } else if (cid === 0xA3) {            // SimpleBlock
          // track VINT + int16 timecode + flags + datos
          var tv = readSize(buf, body);       // número de track
          var dataStart = tv.next + 3;        // +2 timecode, +1 flags
          if (dataStart < bodyEnd) packets.push(buf.subarray(dataStart, bodyEnd));
        }
        p = bodyEnd;
      }
    }

    walk(0, buf.length, false);
    return { opusHead: opusHead, packets: packets };
  }

  // ── Escritor Ogg ───────────────────────────────────────────────────────────
  function OggWriter() { this.chunks = []; this.seq = 0; this.serial = (Math.random() * 0xffffffff) >>> 0; }

  // Escribe UNA página Ogg. segments = arreglo de Uint8Array (paquetes de la página).
  OggWriter.prototype.page = function (segments, granule, flags) {
    // Tabla de segmentos: cada paquete se parte en trozos de 255 bytes (lacing)
    var lacing = [];
    var payloadLen = 0;
    segments.forEach(function (seg) {
      var rem = seg.length;
      while (rem >= 255) { lacing.push(255); rem -= 255; }
      lacing.push(rem);            // el último trozo (< 255) cierra el paquete
      payloadLen += seg.length;
    });

    var header = new Uint8Array(27 + lacing.length);
    var dv = new DataView(header.buffer);
    header[0] = 0x4F; header[1] = 0x67; header[2] = 0x67; header[3] = 0x53;  // "OggS"
    header[4] = 0;                              // versión
    header[5] = flags;                          // 2=BOS, 4=EOS, 0=normal
    // granule position (64 bits little-endian): -1 se escribe como 0xFFFF...
    if (granule < 0) { dv.setUint32(6, 0xffffffff, true); dv.setUint32(10, 0xffffffff, true); }
    else { dv.setUint32(6, granule >>> 0, true); dv.setUint32(10, Math.floor(granule / 4294967296) >>> 0, true); }
    dv.setUint32(14, this.serial, true);        // serial del bitstream
    dv.setUint32(18, this.seq++, true);         // número de página
    dv.setUint32(22, 0, true);                  // CRC (se calcula abajo)
    header[26] = lacing.length;                 // cantidad de segmentos
    header.set(lacing, 27);

    var page = new Uint8Array(header.length + payloadLen), o = header.length;
    page.set(header, 0);
    segments.forEach(function (seg) { page.set(seg, o); o += seg.length; });

    var c = crc32(page);
    page[22] = c & 0xff; page[23] = (c >>> 8) & 0xff; page[24] = (c >>> 16) & 0xff; page[25] = (c >>> 24) & 0xff;

    this.chunks.push(page);
  };

  OggWriter.prototype.blob = function () { return new Blob(this.chunks, { type: 'audio/ogg' }); };

  // OpusHead por defecto (1 canal, 48 kHz) si el WebM no trajo CodecPrivate
  function opusHeadDefault() {
    var h = new Uint8Array(19);
    h.set([0x4F, 0x70, 0x75, 0x73, 0x48, 0x65, 0x61, 0x64], 0); // "OpusHead"
    h[8] = 1;    // versión
    h[9] = 1;    // canales
    // pre-skip (10-11) = 3840, sample rate (12-15) = 48000, gain (16-17) = 0, mapping (18) = 0
    h[10] = 0x00; h[11] = 0x0F;
    h[12] = 0x80; h[13] = 0xBB; h[14] = 0x00; h[15] = 0x00;
    return h;
  }

  function opusTags() {
    var vendor = 'GGI';
    var t = new Uint8Array(8 + 4 + vendor.length + 4);
    t.set([0x4F, 0x70, 0x75, 0x73, 0x54, 0x61, 0x67, 0x73], 0); // "OpusTags"
    var dv = new DataView(t.buffer);
    dv.setUint32(8, vendor.length, true);
    for (var i = 0; i < vendor.length; i++) t[12 + i] = vendor.charCodeAt(i);
    dv.setUint32(12 + vendor.length, 0, true);   // 0 comentarios
    return t;
  }

  // Duración de un paquete Opus en samples a 48 kHz, leída del TOC byte.
  function opusSamples(pkt) {
    if (!pkt.length) return 960;
    var toc = pkt[0], config = toc >> 3, code = toc & 0x3;
    // Tabla de duración de frame (ms) según config (0-31)
    var fr = [10, 20, 40, 60, 10, 20, 40, 60, 10, 20, 40, 60, 10, 20, 10, 20,
              2.5, 5, 10, 20, 2.5, 5, 10, 20, 2.5, 5, 10, 20, 2.5, 5, 10, 20][config];
    var frames = code === 0 ? 1 : (code === 3 ? (pkt[1] & 0x3f) : 2);
    return Math.round((fr * 48) * frames);      // ms * 48 samples/ms
  }

  /** Convierte un Blob WebM/Opus en un Blob Ogg/Opus. */
  global.webmOpusToOgg = function (webmBlob) {
    return webmBlob.arrayBuffer().then(function (ab) {
      var buf = new Uint8Array(ab);
      var parsed = parseWebm(buf);
      var head = parsed.opusHead && parsed.opusHead.length >= 19 ? parsed.opusHead : opusHeadDefault();
      var pkts = parsed.packets;
      if (!pkts.length) throw new Error('No se encontró audio Opus en la grabación');

      var w = new OggWriter();
      w.page([head], 0, 2);        // página BOS con OpusHead
      w.page([opusTags()], 0, 0);  // OpusTags

      // Paquetes de audio: uno o pocos por página, con granule acumulado.
      var granule = 0;
      for (var i = 0; i < pkts.length; i++) {
        granule += opusSamples(pkts[i]);
        var eos = (i === pkts.length - 1);
        w.page([pkts[i]], granule, eos ? 4 : 0);
      }

      return w.blob();
    });
  };
})(typeof window !== 'undefined' ? window : this);
