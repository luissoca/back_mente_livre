-- 1. Agregar las nuevas columnas
ALTER TABLE "appointment_payments"
  ADD COLUMN "external_payment_id" VARCHAR(100),
  ADD COLUMN "external_status" VARCHAR(50),
  ADD COLUMN "external_status_detail" VARCHAR(100);

-- 2. Agregar comentarios (Opcional, equivalente al COMMENT de MySQL)
COMMENT ON COLUMN "appointment_payments"."external_payment_id" IS 'ID del pago en pasarela externa (MercadoPago)';
COMMENT ON COLUMN "appointment_payments"."external_status" IS 'Estado del pago en pasarela externa (approved, pending, rejected)';
COMMENT ON COLUMN "appointment_payments"."external_status_detail" IS 'Detalle del estado en pasarela externa';

-- 3. Crear el índice (Postgres usa CREATE INDEX por separado)
CREATE INDEX IF NOT EXISTS "idx_appointment_payments_external_id" 
ON "appointment_payments" ("external_payment_id");