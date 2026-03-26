-- Migration 011: Add Session Packages (PostgreSQL)

-- Tabla de paquetes de sesiones configurables por admin
CREATE TABLE IF NOT EXISTS session_packages (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name VARCHAR(100) NOT NULL,
  session_count SMALLINT NOT NULL,
  discount_percent SMALLINT NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_session_packages_active ON session_packages(is_active);

DROP TRIGGER IF EXISTS update_session_packages_updated_at ON session_packages;
CREATE TRIGGER update_session_packages_updated_at BEFORE UPDATE ON session_packages FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Tabla de paquetes comprados por pacientes
CREATE TABLE IF NOT EXISTS patient_packages (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  package_id UUID NOT NULL REFERENCES session_packages(id) ON DELETE RESTRICT,
  therapist_id UUID NOT NULL REFERENCES therapists(id) ON DELETE CASCADE,
  user_id UUID REFERENCES users(id) ON DELETE SET NULL,
  patient_email VARCHAR(255) NOT NULL,
  total_sessions SMALLINT NOT NULL,
  used_sessions SMALLINT NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'completed', 'cancelled')),
  total_price_paid DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_patient_packages_user ON patient_packages(user_id);
CREATE INDEX IF NOT EXISTS idx_patient_packages_email ON patient_packages(patient_email);
CREATE INDEX IF NOT EXISTS idx_patient_packages_therapist ON patient_packages(therapist_id);
CREATE INDEX IF NOT EXISTS idx_patient_packages_status ON patient_packages(status);

DROP TRIGGER IF EXISTS update_patient_packages_updated_at ON patient_packages;
CREATE TRIGGER update_patient_packages_updated_at BEFORE UPDATE ON patient_packages FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Add patient_package_id to appointments
ALTER TABLE appointments 
ADD COLUMN IF NOT EXISTS patient_package_id UUID REFERENCES patient_packages(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_appointments_patient_package ON appointments(patient_package_id);
