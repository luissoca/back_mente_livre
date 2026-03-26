-- ============================================================================
-- 006: Valor por defecto para site_content.purpose (POSTGRESQL)
-- ============================================================================

UPDATE "site_content"
SET "purpose" = 'Acompañar a jóvenes en su bienestar emocional con profesionalismo y calidez.'
WHERE "id" = '00000000-0000-0000-0000-000000000001'
  AND (
    "purpose" IS NULL 
    OR TRIM(COALESCE("purpose", '')) = ''
  );