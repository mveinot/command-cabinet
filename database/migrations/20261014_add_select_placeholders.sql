ALTER TABLE command_placeholders
    ADD COLUMN IF NOT EXISTS select_options JSONB;

ALTER TABLE command_placeholders DROP CONSTRAINT IF EXISTS command_placeholders_input_type_check;
ALTER TABLE command_placeholders
    ADD CONSTRAINT command_placeholders_input_type_check
    CHECK (input_type IN ('text', 'password', 'hostname', 'path', 'port', 'multiline', 'select'));
