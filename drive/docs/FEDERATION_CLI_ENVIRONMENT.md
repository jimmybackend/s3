# FederationCloud CLI environment and migration order

FederationCloud has web entrypoints handled by PHP-FPM and background CLI jobs handled by systemd. They must see the same runtime configuration.

## Why this matters

A command started manually from an SSH shell does not automatically inherit the environment configured for `php-fpm-drive.service` or its pool. In particular, a manual command such as:

```bash
php drive/bin/federation_catalog_migrate.php
```

can fail with missing `DB_HOST`, `DB_USER`, `DB_PASSWORD` or `DB_NAME` even while the web Drive is working correctly.

If the schema migration does not run, the gossip worker cannot start because tables such as `FederationEvents` do not exist yet.

## Supported systemd model

`drive/bin/install_federation_sync_timer.sh` creates two oneshot services plus the timer:

```text
arcadecloud-federation-migrate.service
arcadecloud-federation-sync.service
arcadecloud-federation-sync.timer
```

Both services run as the configured PHP-FPM user and load the same optional EnvironmentFile sources:

```text
/etc/arcadecloud-drive/drive.env
/etc/arcadecloud-drive/federation.env
```

`app_bootstrap.php` additionally loads `/etc/arcadecloud-drive/runtime-env.json` through `ManagedRuntimeEnvironment`, so values managed from the Server UI keep their normal precedence.

The installer order is deliberately:

```text
write units
  -> daemon-reload
  -> run migration service
  -> migration must succeed
  -> enable/start sync timer
```

If migration fails, the installer exits non-zero and does not enable a new timer run.

## Installation

```bash
sudo bash drive/bin/install_federation_sync_timer.sh \
  --run-user=nginx \
  --app-root=/var/www/arcadecloud-drive \
  --interval-sec=120
```

Optional paths can be overridden:

```text
--drive-env=/absolute/path/to/drive.env
--federation-env=/absolute/path/to/federation.env
```

Never copy DB/AWS/SMTP secrets into repository files. The repository only documents the environment-loading mechanism; real credentials remain under the server's private `/etc` configuration or managed runtime environment.

## Diagnostics

Migration:

```bash
sudo systemctl status arcadecloud-federation-migrate.service --no-pager
sudo journalctl -u arcadecloud-federation-migrate.service -n 50 --no-pager
```

Worker:

```bash
sudo systemctl status arcadecloud-federation-sync.service --no-pager
sudo journalctl -u arcadecloud-federation-sync.service -n 50 --no-pager
```

Timer:

```bash
sudo systemctl status arcadecloud-federation-sync.timer --no-pager
sudo systemctl list-timers arcadecloud-federation-sync.timer --no-pager
```

A peer being offline is not a schema or bootstrap failure. Once the local schema exists, peer failures are handled by FederationCloud backoff and eventual retry.
