import re
import uuid

def fix_uuid_format(malformed_str):
    # 1. Quitamos todo lo que no sea un caracter hexadecimal (0-9, a-f)
    clean_hex = re.sub(r'[^0-9a-fA-F]', '', malformed_str)
    
    # 2. Si tiene los 32 caracteres exactos, los re-formateamos correctamente
    if len(clean_hex) == 32:
        return f"{clean_hex[:8]}-{clean_hex[8:12]}-{clean_hex[12:16]}-{clean_hex[16:20]}-{clean_hex[20:]}"
    
    # 3. Si está muy roto y no tiene 32 caracteres, generamos uno nuevo para que no falle el SQL
    return str(uuid.uuid4())

def process_document(input_file, output_file):
    # Regex para buscar algo que parezca un UUID mal hecho: 
    # Mucha combinación de hex y guiones (entre 30 y 45 caracteres de largo)
    uuid_pattern = re.compile(r'[0-9a-fA-F\-]{30,45}')

    try:
        with open(input_file, 'r', encoding='utf-8') as f:
            content = f.read()

        # Buscamos todas las coincidencias y aplicamos la función de limpieza
        fixed_content = uuid_pattern.sub(lambda m: fix_uuid_format(m.group(0)), content)

        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(fixed_content)
            
        print(f"¡Listo! Documento procesado. Se guardó como: {output_file}")

    except FileNotFoundError:
        print("Error: No encontré el archivo de entrada.")

# --- Configuración ---
archivo_entrada = r'D:\Github\MenteLivre\back_mente_livre\database\setup_postgres.sql'
archivo_salida = r'D:\Github\MenteLivre\back_mente_livre\database\setup_postgres_fixed.sql'

if __name__ == "__main__":
    process_document(archivo_entrada, archivo_salida)