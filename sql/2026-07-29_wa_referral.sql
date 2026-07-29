-- Referral de Click-to-WhatsApp Ads: guarda el contexto del anuncio del que
-- vino el cliente (titular, cuerpo, thumbnail, source_url) en el mensaje.
ALTER TABLE wa_mensajes
  ADD COLUMN referral_json TEXT NULL AFTER texto;
