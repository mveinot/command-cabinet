ALTER TABLE commands
    ADD COLUMN IF NOT EXISTS last_copied_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS last_copied_by BIGINT REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS commands_last_copied_idx ON commands (last_copied_at DESC);
