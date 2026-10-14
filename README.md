# Command Cabinet

A private, shared command-line catalog for a small team. Commands are stored as templates and can be copied either raw or filled in locally in the browser. Filled placeholder values are never sent to the server or saved to PostgreSQL.

## Requirements

- PHP 8.3 with `pdo_pgsql`
- PostgreSQL 15+
- Composer (for the WebAuthn passkey dependency)

## Setup

1. Create a database named `command_cabinet`.
2. Run `psql command_cabinet < database/schema.sql`.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Copy `.env.example` to `.env` and set the database connection details and `APP_KEY`.
5. Start a local server with `php -S localhost:8000 -t public public/index.php`.
6. Visit `/register` to create the initial administrator account.

For production, set `APP_ENV=production`, serve `public/` as the document root, and configure `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Invitation email is handed to PHP's local `sendmail` transport, which works with a locally configured Postfix node.

## Passkeys

Passkeys use WebAuthn and require HTTPS (except for `localhost` development). Set `WEBAUTHN_ORIGIN` to the exact public origin, such as `https://commands.example.com`, and set `WEBAUTHN_RP_ID` to its hostname, such as `commands.example.com`. The values must continue to match the hostname where passkeys are used.

Users add and remove passkeys from **Passkeys** in the account navigation. Password sign-in and password reset remain available as recovery methods.

## Template syntax

Use `{{placeholder_key}}` inside commands, for example:

```sh
ssh {{user}}@{{host}} "cd {{project_path}} && git pull"
```

Placeholders have labels, optional help text, types, and required flags. The browser parses the template and renders values only when the person clicks **Copy filled command**. Those values do not appear in requests, logs, browser storage, or the database.
