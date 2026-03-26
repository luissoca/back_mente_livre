import re
import sys

def convert_seed(input_file, output_file):
    with open(input_file, 'r', encoding='utf-8') as f:
        content = f.read()

    # Define PKs/Unique keys for ON CONFLICT
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
        'team_profiles': 'id'
    }

    # Remove MySQL specific SET commands
    content = re.sub(r'SET FOREIGN_KEY_CHECKS.*?;', '', content, flags=re.DOTALL)
    content = re.sub(r'SET SQL_MODE.*?;', '', content, flags=re.DOTALL)
    content = re.sub(r'SET time_zone.*?;', '', content, flags=re.DOTALL)

    # Convert backticks to nothing (standard SQL)
    content = content.replace('`', '')

    # Convert UUID() to gen_random_uuid()
    content = content.replace('UUID()', 'gen_random_uuid()')

    # Convert JSON_ARRAY to jsonb_build_array
    content = re.sub(r'JSON_ARRAY\(', 'jsonb_build_array(', content, flags=re.IGNORECASE)

    # Fix string escaping: MySQL \' -> Postgres ''
    # We need to be careful not to double replace. 
    # A safe way is to split by single quotes and reassemble, but regex is easier if careful.
    # MySQL uses \' for escaping ' inside '. Postgres uses ''.
    # Also MySQL might use \" inside '.
    
    # Simple approach for \' -> ''
    # content = content.replace("\\'", "''") 
    # But wait, this might break if \\' was meant to be a backslash then quote?
    # Assuming standard dump behavior:
    content = content.replace("\\'", "''")
    content = content.replace('\\"', '"')

    # Convert ON DUPLICATE KEY UPDATE
    # Pattern: INSERT INTO table_name ... ON DUPLICATE KEY UPDATE ...;
    # We need to capture table name to determine constraints.
    
    def replacer(match):
        table_name = match.group(1)
        update_part = match.group(2)
        
        # trim whitespace
        table_name = table_name.strip()
        
        constraint = pk_map.get(table_name, 'id')
        
        # Convert syntax
        # MySQL: col = VALUES(col)
        # Postgres: col = EXCLUDED.col
        
        update_part = re.sub(r'VALUES\((.*?)\)', r'EXCLUDED.\1', update_part, flags=re.IGNORECASE)
        
        return f"INSERT INTO {table_name} {match.group(2).split('VALUES')[0].strip()} VALUES {match.group(2).split('VALUES', 1)[1].split('ON DUPLICATE KEY')[0].strip()} ON CONFLICT ({constraint}) DO UPDATE SET {update_part}"

    # Complex regex to capture INSERT INTO ... ON DUPLICATE KEY UPDATE
    # Since existing file structure is:
    # INSERT INTO table (...) VALUES ... 
    # ON DUPLICATE KEY UPDATE ...;
    
    # We can process purely line by line or block by block?
    # No, simple regex replace on the whole file might be risky with greedy matches.
    
    # Let's split by statement terminator ';'
    statements = content.split(';')
    new_statements = []
    
    for stmt in statements:
        if not stmt.strip():
            continue
            
        # Check if INSERT
        if 'INSERT INTO' in stmt and 'ON DUPLICATE KEY UPDATE' in stmt:
            # Extract table name
            m = re.search(r'INSERT INTO\s+([a-zA-Z0-9_]+)', stmt)
            if m:
                table_name = m.group(1)
                constraint = pk_map.get(table_name, 'id')
                
                # Replace ON DUPLICATE KEY UPDATE
                # Also replace VALUES(col) with EXCLUDED.col
                
                parts = stmt.split('ON DUPLICATE KEY UPDATE')
                insert_part = parts[0]
                update_part = parts[1]
                
                update_part = re.sub(r'VALUES\((.*?)\)', r'EXCLUDED.\1', update_part, flags=re.IGNORECASE)
                
                new_stmt = f"{insert_part} ON CONFLICT ({constraint}) DO UPDATE SET {update_part}"
                new_statements.append(new_stmt)
            else:
                new_statements.append(stmt)
        else:
            new_statements.append(stmt)
            
    final_content = ';\n'.join(new_statements) + ';\n'
    
    # Additional cleanup
    final_content = re.sub(r'ENGINE=InnoDB.*?;', ';', final_content)
    
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(final_content)

if __name__ == '__main__':
    convert_seed('d:/Github/MenteLivre/back_mente_livre/database/001_seed_production_data.sql', 'd:/Github/MenteLivre/back_mente_livre/database/postgres_data.sql')
