-- ============================================================================
-- MIGRACIÓN: Tabla de Refresh Tokens (Reemplazo de almacenamiento en archivo)
-- ============================================================================

-- Tabla de refresh tokens
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
    user_id CHAR(36) NOT NULL COMMENT 'FK a users.id',
    token VARCHAR(500) NOT NULL COMMENT 'El refresh token (JWT)',
    expires_at DATETIME NOT NULL COMMENT 'Fecha de expiración',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_refresh_tokens_user (user_id),
    INDEX idx_refresh_tokens_token (token),
    INDEX idx_refresh_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Almacenamiento persistente de refresh tokens';
