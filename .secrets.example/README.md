# Credentials

The plugin never stores Payrails credentials in the database. It reads them from
`PAYRAILS_*` environment variables, or else from the INI file named by the
`PAYRAILS_SECRETS_FILE` constant, which `scripts/setup.sh` writes into `wp-config.php`
and points at `.secrets/payrails.env`.

Put these three files in `.secrets/` (gitignored), then run `scripts/setup.sh`:

| File | What |
|---|---|
| `payrails.env` | A copy of `payrails.env.example` with your staging API URL, client id, client secret, workspace id and workflow code (all required except the workflow code) |
| `client.crt` | The mTLS client certificate Payrails issued for your client |
| `client.key` | Its private key |

```bash
mkdir -p .secrets && chmod 700 .secrets
cp .secrets.example/payrails.env.example .secrets/payrails.env
cp /path/to/your/client.crt /path/to/your/client.key .secrets/
chmod 600 .secrets/*
# now edit .secrets/payrails.env and replace the placeholders
```

`PAYRAILS_CERT_PATH` and `PAYRAILS_KEY_PATH` in the example are relative to this folder
(`client.crt`, `client.key`). Absolute paths work too.

`scripts/setup.sh` also creates `.secrets/admin.txt` with the WordPress admin user name and
a random password.
