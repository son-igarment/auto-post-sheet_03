Local Run with Docker Compose
=============================

Requirements
- Docker Desktop (Windows/macOS) or Docker Engine (Linux).

Services
- WordPress (php8.2-apache) on http://localhost:8080
- MariaDB 11.x
- phpMyAdmin on http://localhost:8081 (root/root)
- Composer helper container to install PHP deps into the plugin

Quick Start
1) Install Composer deps into the plugin folder:
   - `docker compose run --rm composer install`

2) Bring up WordPress + DB:
   - `docker compose up -d`

3) Open WordPress at http://localhost:8080 and complete the setup wizard.

4) In WP Admin → Plugins, activate “Auto Post From Google Sheet”.

5) In the “Auto Post Sheet” menu:
   - Fill “Google Sheet ID”.
   - Range example: `Sheet1!A2:G1000`.
   - Upload your Service Account JSON file.
   - Optionally set Social Webhook, Telegram, Email, Cache TTL and Retry.

Notes
- This repo is mounted into the container at `/var/www/html/wp-content/plugins/auto-post-sheet`.
- Composer installs vendor/ into the same plugin folder (shared via bind mount).
- WP debug is enabled; check `/wp-content/debug.log` in the container (or mapped volume).

