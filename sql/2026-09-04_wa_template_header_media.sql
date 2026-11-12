-- Header de IMAGEN para plantillas (flyers de promo, etc.).
-- Meta exige la media del header en CADA envío aunque sea siempre la misma;
-- la guardamos aquí (URL pública HTTPS) y el envío/campaña la adjunta sola.
ALTER TABLE whatsapp_templates
  ADD COLUMN header_media_url VARCHAR(500) NULL DEFAULT NULL AFTER url_button_key;
