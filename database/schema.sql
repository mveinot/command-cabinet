CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'member', 'viewer')),
    email_verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE password_resets (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX password_resets_active_user_idx ON password_resets (user_id, created_at DESC) WHERE used_at IS NULL;

CREATE TABLE invitations (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'member', 'viewer')),
    token_hash CHAR(64) NOT NULL UNIQUE,
    invited_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    expires_at TIMESTAMPTZ NOT NULL,
    accepted_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX invitations_active_email_idx ON invitations (email, expires_at) WHERE accepted_at IS NULL;

CREATE TABLE commands (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    template TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    environment VARCHAR(80) NOT NULL,
    safety_level VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK (safety_level IN ('normal', 'caution', 'destructive')),
    created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    last_verified_at DATE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    archived_at TIMESTAMPTZ,
    archived_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    last_copied_at TIMESTAMPTZ,
    last_copied_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    search_document TSVECTOR GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(description, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(template, '')), 'A')
    ) STORED
);
CREATE INDEX commands_search_idx ON commands USING GIN (search_document);
CREATE INDEX commands_environment_idx ON commands (environment);
CREATE INDEX commands_updated_idx ON commands (updated_at DESC);
CREATE INDEX commands_active_updated_idx ON commands (updated_at DESC) WHERE archived_at IS NULL;
CREATE INDEX commands_last_copied_idx ON commands (last_copied_at DESC);

CREATE TABLE command_placeholders (
    id BIGSERIAL PRIMARY KEY,
    command_id BIGINT NOT NULL REFERENCES commands(id) ON DELETE CASCADE,
    placeholder_key VARCHAR(80) NOT NULL,
    label VARCHAR(120) NOT NULL,
    input_type VARCHAR(20) NOT NULL DEFAULT 'text' CHECK (input_type IN ('text', 'password', 'hostname', 'path', 'port', 'multiline', 'select')),
    help_text VARCHAR(500),
    example_value VARCHAR(500),
    select_options JSONB,
    is_required BOOLEAN NOT NULL DEFAULT TRUE,
    position SMALLINT NOT NULL DEFAULT 0,
    UNIQUE (command_id, placeholder_key)
);

CREATE TABLE tags (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE command_tags (
    command_id BIGINT NOT NULL REFERENCES commands(id) ON DELETE CASCADE,
    tag_id BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (command_id, tag_id)
);

CREATE TABLE command_versions (
    id BIGSERIAL PRIMARY KEY,
    command_id BIGINT NOT NULL REFERENCES commands(id) ON DELETE CASCADE,
    title VARCHAR(180) NOT NULL,
    template TEXT NOT NULL,
    description TEXT NOT NULL,
    environment VARCHAR(80) NOT NULL,
    safety_level VARCHAR(20) NOT NULL,
    last_verified_at DATE,
    placeholders JSONB,
    tag_names JSONB,
    changed_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    change_note VARCHAR(500),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX command_versions_command_idx ON command_versions (command_id, created_at DESC);

CREATE TABLE favorites (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    command_id BIGINT NOT NULL REFERENCES commands(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, command_id)
);
