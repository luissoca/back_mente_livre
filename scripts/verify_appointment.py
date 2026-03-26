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

def verify_appointment(appointment_id):
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
        
        print("\n--- Verificando CITA (appointment_id) ---")
        print(f"Buscando ID: {appointment_id}")
        
        # Check by ID
        cur.execute("SELECT id, therapist_id, user_id, patient_contact_id, appointment_date, status FROM appointments WHERE id = %s", (appointment_id,))
        appointment = cur.fetchone()
        
        if appointment:
            print(f"✅ Cita encontrada:")
            print(f"   ID: {appointment[0]}")
            print(f"   Therapist: {appointment[1]}")
            print(f"   User: {appointment[2]}")
            print(f"   Patient Contact: {appointment[3]}")
            print(f"   Date: {appointment[4]}")
            print(f"   Status: {appointment[5]}")
        else:
            print(f"❌ Cita NO encontrada por ID.")
            
        cur.close()
        conn.close()
        
    except Exception as e:
        print(f"\n❌ Error de conexión o ejecución: {e}")
        print("Asegúrate de tener instaladas las dependencias: pip install psycopg2 psycopg2-binary python-dotenv")

if __name__ == "__main__":
    # ID tomado del error log
    TARGET_APPOINTMENT_ID = "509a04fa-fed2-449b-8adf-de56b41e0aeb"
    
    print("Iniciando verificación de cita...")
    verify_appointment(TARGET_APPOINTMENT_ID)
