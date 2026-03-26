-- Migration 008: Add Culqi Support
-- Reusing existing columns from 007 (external_payment_id, etc.)
-- Just need to ensure payment_method enum or check allows 'culqi'
-- If payment_method is VARCHAR(50) (it is), we are good.

-- We can add an index or specific column if needed, but 007 covered the basics.
-- Let's just create a placeholder migration to track this change in history.

SELECT 1; -- No schema changes required over 007
