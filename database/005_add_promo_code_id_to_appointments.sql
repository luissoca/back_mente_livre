DO $$ 
BEGIN 
    -- 1. Verificar si la tabla de destino existe
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'appointments') THEN
        
        -- 2. Verificar si la columna NO existe aún
        IF NOT EXISTS (
            SELECT 1 
            FROM information_schema.columns 
            WHERE table_name = 'appointments' 
            AND column_name = 'promo_code_id'
        ) THEN
            
            -- 3. Verificar si la tabla de referencia existe antes de crear el FK
            IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'promo_codes') THEN
                
                ALTER TABLE "appointments" 
                ADD COLUMN "promo_code_id" UUID; -- O CHAR(36) si usas strings, pero UUID es preferible en Postgres

                -- 4. Añadir la FK e índice (Postgres crea el índice automáticamente para FKs en algunas versiones, pero lo definimos)
                ALTER TABLE "appointments"
                ADD CONSTRAINT "fk_appointments_promo_code" 
                FOREIGN KEY ("promo_code_id") 
                REFERENCES "promo_codes"("id") 
                ON DELETE SET NULL;

                CREATE INDEX IF NOT EXISTS "idx_appointments_promo_code" ON "appointments"("promo_code_id");

                RAISE NOTICE 'Columna promo_code_id añadida exitosamente.';
            ELSE
                RAISE WARNING 'La tabla promo_codes no existe. No se pudo añadir la columna.';
            END IF;
            
        END IF;
    END IF;
END $$;