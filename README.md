# auto-post-sheet_03

Auto-post WordPress plugin that pulls posts from Google Sheets using a Service Account. Extended features:

- Retry policy and centralized logging via `AutoPostLogger` and `RetryHelper`.
- Optional Socials posting by sending a JSON payload to your Bot Central/webhook.
- REST endpoint `/wp-json/auto-post-sheet/v1/clickup-webhook` to append a new row from ClickUp to the Sheet.
- Heartbeat cron every 5 minutes and daily reports (08:00 and 22:00) via email/Telegram.

Basic setup: open the “Auto Post Sheet” admin page and fill Sheet ID, Range, upload Service Account JSON. Optionally add Social Webhook URL, platforms list, and notification settings.
