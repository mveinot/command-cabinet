CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id VARCHAR(1024) NOT NULL UNIQUE,
    credential_data JSONB NOT NULL,
    label VARCHAR(120) NOT NULL DEFAULT 'Passkey',
    aaguid UUID,
    backup_eligible BOOLEAN,
    backup_status BOOLEAN,
    uv_initialized BOOLEAN,
    last_used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS webauthn_credentials_user_idx ON webauthn_credentials (user_id, created_at DESC);
