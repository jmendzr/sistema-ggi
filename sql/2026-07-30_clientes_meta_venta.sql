-- Meta Ads / Conversions API: marca de venta ya reportada por cliente.
-- Regla: una sola venta (conversión) por cliente. Si el cliente ya generó una
-- venta reportada a Meta (primer pago validado de una propuesta aceptada que
-- vino de un Click-to-WhatsApp Ad), una segunda propuesta/ISO del mismo cliente
-- NO cuenta como nueva venta. Este timestamp evita el reenvío.
ALTER TABLE clientes
  ADD COLUMN meta_venta_reportada_at DATETIME NULL DEFAULT NULL AFTER estado;
