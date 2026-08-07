# DDEV

This project runs in DDEV — the host has no matching PHP/DB setup.

- Run every PHP-related command through the container: `ddev exec vendor/bin/typo3 ...`, `ddev composer ...`, `ddev mysql`. Never run host `php`/`composer` against this project.
- Project URLs come from `.ddev/config.yaml` (`name` + `project_tld`); don't guess hostnames. `ddev describe` shows services and ports.
- Mails are caught by Mailpit (`ddev launch -m` or the URL from `ddev describe`) — nothing leaves the machine.
- Debugging: `ddev xdebug on` (off by default for performance).
- Logs: `ddev logs -f` for the webserver; TYPO3's own logs via the `read_log_entries` tool.
