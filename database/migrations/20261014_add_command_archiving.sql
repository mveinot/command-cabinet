ALTER TABLE commands
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS archived_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS commands_active_updated_idx
    ON commands (updated_at DESC)
    WHERE archived_at IS NULL;
