import re
import os
import glob

def convert_mysql_to_postgres(content, filename=""):
    # Basic cleanup
    content = re.sub(r'SET FOREIGN_KEY_CHECKS.*?;', '', content, flags=re.DOTALL)
    content = re.sub(r'SET SQL_MODE.*?;', '', content, flags=re.DOTALL)
    content = re.sub(r'SET time_zone.*?;', '', content, flags=re.DOTALL)
    content = re.sub(r'ENGINE=InnoDB.*?;', ';', content)
    
    # Remove backticks
    content = content.replace('`', '')
    
    # Data types
    content = re.sub(r'\bDATETIME\b', 'TIMESTAMP', content, flags=re.IGNORECASE)
    content = re.sub(r'\bCHAR\(36\)\b', 'UUID', content, flags=re.IGNORECASE)
    content = re.sub(r'\bINT UNSIGNED\b', 'INTEGER', content, flags=re.IGNORECASE)
    content = re.sub(r'\bTINYINT UNSIGNED\b', 'SMALLINT', content, flags=re.IGNORECASE)
    
    # Functions
    content = content.replace('UUID()', 'gen_random_uuid()')
    content = re.sub(r'JSON_ARRAY\(', 'jsonb_build_array(', content, flags=re.IGNORECASE)
    
    # SUBSTRING_INDEX for file paths (specific to 002)
    # MySQL: SUBSTRING_INDEX(url, '/', -1)
    # Postgres: substring(url from '[^/]+$')
    content = re.sub(r"SUBSTRING_INDEX\((.*?), '/', -1\)", r"substring(\1 from '[^/]+$')", content, flags=re.IGNORECASE)
    
    # AFTER column (not supported in Postgres)
    content = re.sub(r'\s+AFTER\s+[a-zA-Z0-9_]+', '', content, flags=re.IGNORECASE)
    
    # IFNULL -> COALESCE
    content = re.sub(r'\bIFNULL\b', 'COALESCE', content, flags=re.IGNORECASE)
    
    if "004_add_password_reset_tokens" in filename:
        return """
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id UUID PRIMARY KEY,
  user_id UUID NOT NULL,
  token VARCHAR(255) NOT NULL UNIQUE,
  expires_at TIMESTAMP NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user ON password_reset_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_expires ON password_reset_tokens(expires_at);
"""

    if "005_add_promo_code_id" in filename:
        # Replaces complex MySQL procedure with simple Postgres ALTER
        return """
-- Replaced MySQL stored procedure with Postgres ALTER TABLE IF EXISTS
DO $$
BEGIN
    BEGIN
        ALTER TABLE appointments ADD COLUMN promo_code_id UUID NULL REFERENCES promo_codes(id) ON DELETE SET NULL;
    EXCEPTION
        WHEN duplicate_column THEN RAISE NOTICE 'column promo_code_id already exists in appointments.';
    END;
END;
$$;
CREATE INDEX IF NOT EXISTS idx_appointments_promo_code ON appointments(promo_code_id);
"""

    if "007_add_mercadopago" in filename:
         # Basic conversions handles types, but we want to ensure idempotency if possible
         # Postgres allows ADD COLUMN IF NOT EXISTS
         content = content.replace("ALTER TABLE appointment_payments", "ALTER TABLE appointment_payments")
         content = content.replace("ADD COLUMN", "ADD COLUMN IF NOT EXISTS")
         content = content.replace("ADD INDEX", "CREATE INDEX IF NOT EXISTS") 
         # Fix bad syntax from Replace: ADD INDEX is not valid in ALTER TABLE for Postgres usually
         # Postgres: CREATE INDEX ... ON ...
         # The script below will handle "ADD INDEX" specially?
         pass

    # Fix ADD INDEX inside ALTER TABLE (MySQL) -> CREATE INDEX (Postgres)
    # MySQL: ALTER TABLE t ADD INDEX i (c);
    # Postgres: CREATE INDEX i ON t (c);
    # This is hard to regex globally.
    # For 007: 
    # ADD INDEX idx_appointment_payments_external_id (external_payment_id);
    # -> ; CREATE INDEX ...
    # MySQL: ALTER TABLE t ADD INDEX i (c);
    # Postgres: CREATE INDEX i ON t (c);
    content = re.sub(r'ALTER TABLE\s+([a-zA-Z0-9_]+)\s+ADD INDEX\s+([a-zA-Z0-9_]+)\s*\((.*?)\);', r'CREATE INDEX IF NOT EXISTS \2 ON \1 (\3);', content, flags=re.IGNORECASE|re.DOTALL)
    
    # Also handle multiline ADD INDEX if necessary, but 007 looks single line/clean enough.
    # If indentation is present: \s+ covers newlines in Python regex? No, only [ \t\n\r\f\v]. Yes.
    
    # Special file handling (continued)

    
    pk_map = {
        'users': 'id',
        'profiles': 'id',
        'roles': 'id',
        'user_roles': 'user_id, role_id',
        'email_classifications': 'id',
        'email_domain_rules': 'domain',
        'therapists': 'id',
        'therapist_pricing': 'therapist_id, pricing_tier',
        'therapist_experience_topics': 'id',
        'therapist_population_served': 'id',
        'therapist_photos': 'id',
        'weekly_schedules': 'therapist_id, day_of_week, start_time',
        'weekly_schedule_overrides': 'therapist_id, week_start_date, day_of_week, start_time',
        'patient_contacts': 'id',
        'promo_codes': 'code',
        'appointments': 'id',
        'appointment_payments': 'id',
        'promo_code_uses': 'id',
        'site_content': 'id',
        'team_profiles': 'id',
        'password_reset_tokens': 'id' 
    }

    statements = content.split(';')
    new_statements = []
    
    for stmt in statements:
        if not stmt.strip():
            continue
            
        # Detect INSERT ... ON DUPLICATE KEY UPDATE
        if 'INSERT INTO' in stmt and 'ON DUPLICATE KEY UPDATE' in stmt:
            m = re.search(r'INSERT INTO\s+([a-zA-Z0-9_]+)', stmt)
            if m:
                table_name = m.group(1).strip()
                constraint = pk_map.get(table_name, 'id')
                
                parts = stmt.split('ON DUPLICATE KEY UPDATE')
                insert_part = parts[0]
                update_part = parts[1]
                
                # VALUES(col) -> EXCLUDED.col
                update_part = re.sub(r'VALUES\((.*?)\)', r'EXCLUDED.\1', update_part, flags=re.IGNORECASE)
                
                # Handle syntax differences in UPDATE SET
                # MySQL allows `col` = ...
                # Postgres requires just col = ... (which we ALREADY handled by removing backticks)
                
                new_stmt = f"{insert_part} ON CONFLICT ({constraint}) DO UPDATE SET {update_part}"
                new_statements.append(new_stmt)
            else:
                new_statements.append(stmt)
        else:
            new_statements.append(stmt)
            
    final_content = ';\n'.join(new_statements)
    if final_content.strip():
        final_content += ';\n'
        
    return final_content

def main():
    base_dir = r'd:/Github/MenteLivre/back_mente_livre/database'
    output_file = os.path.join(base_dir, 'setup_postgres.sql')
    
    # 1. Read schema_postgres.sql (created previously)
    schema_path = os.path.join(base_dir, 'schema_postgres.sql')
    if os.path.exists(schema_path):
        with open(schema_path, 'r', encoding='utf-8') as f:
            full_sql = f.read() + "\n\n"
    else:
        print("Warning: schema_postgres.sql not found. Starting empty.")
        full_sql = ""
        
    # 2. Process numbered files 001..009
    files = sorted(glob.glob(os.path.join(base_dir, '0*.sql')))
    
    for file_path in files:
        filename = os.path.basename(file_path)
        print(f"Processing {filename}...")
        
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
            
        converted = convert_mysql_to_postgres(content, filename)
        
        full_sql += f"-- SOURCE: {filename}\n"
        full_sql += converted + "\n\n"
        
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(full_sql)
        
    print(f"Created {output_file}")

if __name__ == '__main__':
    main()
