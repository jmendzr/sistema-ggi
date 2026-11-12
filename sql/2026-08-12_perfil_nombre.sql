-- Nombre de perfil de WhatsApp del contacto (value.contacts[].profile.name del webhook).
ALTER TABLE wa_conversaciones
  ADD COLUMN perfil_nombre VARCHAR(120) NULL AFTER telefono;
