ALTER TABLE command_versions
    ADD COLUMN IF NOT EXISTS placeholders JSONB,
    ADD COLUMN IF NOT EXISTS tag_names JSONB,
    ADD COLUMN IF NOT EXISTS last_verified_at DATE;
