import os
import psycopg2
from dotenv import load_dotenv

# Load environment variables from .env file ONE directory up (in back_mente_livre root)
env_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), '.env')
print(f"Loading .env from: {env_path}")
load_dotenv(env_path)

# Configuration
DB_HOST = os.getenv('DB_HOST')
DB_NAME = os.getenv('DB_DATABASE')
DB_USER = os.getenv('DB_USER')
DB_PASS = os.getenv('DB_PASSWORD')
DB_PORT = os.getenv('DB_PORT', '5432')
DB_SSLMODE = os.getenv('DB_SSLMODE', 'require')

def verify_data(user_id, therapist_id, email):
    if not DB_HOST or not DB_PASS:
        print("❌ Error: DB_HOST or DB_PASSWORD not found in .env file.")
        print("Please ensure back_mente_livre/.env contains your production database credentials.")
        return

    try:
        print(f"Connecting to database '{DB_NAME}' at {DB_HOST}...")
        
        # Neon SNI workaround: pass endpoint ID in options
        conn_options = None
        if 'neon.tech' in DB_HOST:
             endpoint_id = DB_HOST.split('.')[0]
             conn_options = f"endpoint={endpoint_id}"
        
        conn = psycopg2.connect(
            host=DB_HOST,
            database=DB_NAME,
            user=DB_USER,
            password=DB_PASS,
            port=DB_PORT,
            sslmode=DB_SSLMODE,
            options=conn_options
        )
        cur = conn.cursor()
        
        print("\n--- 1. Verificando USUARIO (user_id) ---")
        print(f"Buscando ID: {user_id}")
        
        # Check by ID
        cur.execute("SELECT id, email FROM users WHERE id = %s", (user_id,))
        user = cur.fetchone()
        
        if user:
            print(f"✅ Usuario encontrado por ID: {user}")
        else:
            print(f"❌ Usuario NO encontrado por ID.")
            
            # Check by Email to see if it exists with a different ID
            print(f"   Buscando si existe por email: {email}")
            cur.execute("SELECT id, email FROM users WHERE email = %s", (email,))
            user_by_email = cur.fetchone()
            if user_by_email:
                print(f"⚠️ El usuario existe pero tiene OTRO ID: {user_by_email[0]}")
                print(f"   Frontend envía: {user_id}")
                print(f"   Backend tiene:  {user_by_email[0]}")
            else:
                print(f"❌ El usuario tampoco existe por email.")

        print("\n--- 2. Verificando TERAPEUTA (therapist_id) ---")
        print(f"Buscando ID: {therapist_id}")
        
        cur.execute("SELECT id, name FROM therapists WHERE id = %s", (therapist_id,))
        therapist = cur.fetchone()
        
        if therapist:
            print(f"✅ Terapeuta encontrado: {therapist}")
        else:
            print(f"❌ Terapeuta NO encontrado.")

        print("\n--- 3. Verificando CONTACTO DE PACIENTE ---")
        print(f"Buscando por email: {email}")
        
        cur.execute("SELECT id, email, full_name FROM patient_contacts WHERE email = %s", (email,))
        contact = cur.fetchone()
        
        if contact:
            print(f"✅ Contacto encontrado: {contact}")
        else:
             print(f"ℹ️ Contacto no encontrado (esto es normal, se crearía durante la reserva).")
             
        cur.close()
        conn.close()
        
    except Exception as e:
        print(f"\n❌ Error de conexión o ejecución: {e}")
        print("Asegúrate de tener instaladas las dependencias: pip install psycopg2 psycopg2-binary python-dotenv")

if __name__ == "__main__":
    # IDs tomados del error log de Vercel/CloudWatch
    TARGET_USER_ID = "aada173b-1f81-4dc6-850f-d8a294729654"
    TARGET_THERAPIST_ID = "6fdc471e-04e9-4f87-90a6-ebbbe6131522"
    TARGET_EMAIL = "arubik4u@gmail.com"
    
    print("Iniciando diagnóstico de integridad de datos...")
    verify_data(TARGET_USER_ID, TARGET_THERAPIST_ID, TARGET_EMAIL)
