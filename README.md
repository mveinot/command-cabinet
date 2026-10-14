# Command Cabinet

> A private, shared command-line catalog for small technical teams.

Command Cabinet keeps the commands your team actually uses in one searchable place. Store a command once, document its caveats, fill temporary values locally, and copy a ready-to-run result without saving secrets or host-specific details.

## Highlights

- **Command templates** — use `{{placeholders}}`, then copy either the untouched command or a locally filled version.
- **Local-only values** — filled placeholder values never reach the server, database, logs, or browser storage.
- **Typed placeholders** — text, password, hostname, path, port, multiline, and selectable preset values.
- **Fast catalog** — live search, environment filtering, tags, favourites, and compact one-click copy for static commands.
- **Useful context** — Markdown notes with an editor preview, safety classifications, verification dates, and related history.
- **Team controls** — administrator, member, and read-only roles; invitations; direct user provisioning; password resets; and account management.
- **Command lifecycle** — clone commands, archive rather than delete, see revision history, and restore a prior revision as an administrator.
- **Activity signal** — see who last copied a command and when.
- **Portable administration** — administrator-only JSON export and import, including command history and account password hashes.
- **Passkeys** — optional WebAuthn passkey registration and sign-in, while passwords and recovery remain available.
- **Terminal-inspired theme** — light, system, and green-terminal dark modes.

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `openssl`, and `mbstring`
- PostgreSQL 15+
- Composer 2+
- Apache with `mod_rewrite` for production (or another web server configured to route requests to `public/index.php`)
- HTTPS in production, required for passkeys

## Quick start

```sh
git clone git@github.com:mveinot/comman-cabinet.git
cd comman-cabinet
composer install --no-dev --optimize-autoloader
createdb command_cabinet
psql command_cabinet < database/schema.sql
cp .env.example .env
```

Edit `.env` with your PostgreSQL connection details and application URL, then start a local server:

```sh
php -S localhost:8000 -t public public/index.php
```

Open `http://localhost:8000/register` to create the first administrator account. Registration is intentionally disabled after that; additional people join by invitation or through the administrator user-management screen.

## Configuration

The included [`.env.example`](.env.example) documents all required values.

| Setting | Purpose |
| --- | --- |
| `APP_URL` | Public application URL; used in email links and as the default passkey origin. |
| `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL connection settings. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity for invitations and password resets. PHP's local `sendmail` transport is used, which works well with Postfix. |
| `WEBAUTHN_ORIGIN` | Exact HTTPS public origin for passkeys, such as `https://commands.example.com`. |
| `WEBAUTHN_RP_ID` | Hostname associated with passkeys, such as `commands.example.com`. |

For local development, `http://localhost:8000` and `localhost` are valid passkey settings. Production passkeys require HTTPS, and the origin/RP ID must remain stable once users have enrolled passkeys.

## Production deployment

1. Set the web server document root to the `public/` directory.
2. Enable Apache `mod_rewrite` and allow the included [`public/.htaccess`](public/.htaccess) rules, or configure equivalent rewrite rules.
3. Run `composer install --no-dev --optimize-autoloader` on the server.
4. Set production values in `.env`, particularly `APP_URL`, mail sender settings, and passkey settings.
5. Create a fresh database with `database/schema.sql`, or apply the relevant SQL files in `database/migrations/` to an existing installation.
6. Ensure PHP can hand mail to the configured local Postfix/sendmail transport.

Never expose the project root, `.env`, `vendor/`, or `database/` directory through the web server.

## Using command templates

Write placeholders in double braces:

```sh
ssh {{user}}@{{host}} "cd {{project_path}} && git pull"
```

When a command is saved, Command Cabinet detects the placeholders and lets the editor assign labels, help text, sample values, required status, and an input type. On the command page, a user may:

1. Copy the raw command with placeholders intact; or
2. Fill the temporary fields and copy a substituted command.

The substituted values are handled only in the open browser tab. They are not submitted with the form or retained after the page is closed.

## Access roles

| Role | Capabilities |
| --- | --- |
| Administrator | Full command management, archive/restore, user administration, exports/imports, invitations, and revision restores. |
| Member | Browse, copy, favourite, create, and edit commands. |
| View only | Browse and copy commands without making catalog changes. |

## Backups and imports

Administrators can export the site as JSON from **Users**. An import replaces the complete catalog and account dataset, including password hashes, passkey credential records, command history, tags, and favourites. Treat exports as sensitive backups and store them securely.

## Security notes

- Passwords are stored only as one-way hashes.
- Passkey private keys stay on the user's authenticator; the application stores only public credential records.
- WebAuthn challenges are short-lived and held server-side in the session.
- Passkey validation verifies the ceremony challenge, origin, relying-party ID, user verification, signature, and replay counter using [`web-auth/webauthn-lib`](https://github.com/web-auth/webauthn-framework).
- CSRF protection is used on state-changing requests.

## Development

Run the lightweight syntax checks before committing changes:

```sh
php -l app/bootstrap.php
php -l app/passkeys.php
php -l public/index.php
node --check public/assets/app.js
```

`vendor/` and `.env` are intentionally ignored. Commit `composer.json`, `composer.lock`, source, schema, and migration files.

## License

Copyright © 2026 Mark Veinot.

Command Cabinet is licensed under the [GNU General Public License, version 2.0 only](LICENSE).
