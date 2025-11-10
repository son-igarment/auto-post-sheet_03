from flask import Flask, jsonify
from googleapiclient.discovery import build
from google.oauth2 import service_account
import os, json, time, random, pathlib

app = Flask(__name__)

# Simple in-memory cache with TTL
_CACHE: dict[str, dict] = {}


def _cache_key(sheet_id: str, rng: str) -> str:
    return f"sheet:{sheet_id}|range:{rng}"


def cache_get(key: str):
    item = _CACHE.get(key)
    if not item:
        return None
    if item.get("exp", 0) < time.time():
        _CACHE.pop(key, None)
        return None
    return item.get("val")


def cache_set(key: str, value, ttl: int):
    _CACHE[key] = {"val": value, "exp": time.time() + max(0, int(ttl))}


def retry(func, retries: int = 3, delay_ms: int = 500, factor: float = 2.0, jitter_ms: int = 0):
    """Retry helper with exponential backoff and optional jitter.
    func receives attempt index and returns value or raises.
    """
    attempt = 0
    while True:
        try:
            return func(attempt)
        except Exception:
            attempt += 1
            if attempt > retries:
                raise
            sleep_ms = max(0, int(delay_ms + (random.randint(-jitter_ms, jitter_ms) if jitter_ms > 0 else 0)))
            time.sleep(sleep_ms / 1000.0)
            delay_ms = int(delay_ms * factor)


def _write_status_json(payload: dict):
    """Write status into 011.json in repo or fallback to /tmp."""
    data = {
        "system": "RetrySystem_1",
        **payload,
    }
    # Try writing to CWD first; fallback to tmp
    candidates = [pathlib.Path.cwd() / "011.json", pathlib.Path(os.environ.get("TMP", "/tmp")) / "011.json"]
    for path in candidates:
        try:
            path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
            return str(path)
        except Exception:
            continue
    return None


@app.route('/')
def home():
    try:
        # Load credentials từ biến môi trường (VD: Vercel)
        creds_info = json.loads(os.environ["GOOGLE_APPLICATION_CREDENTIALS_JSON"])
        creds = service_account.Credentials.from_service_account_info(creds_info)

        # Kết nối Google Sheets API
        service = build('sheets', 'v4', credentials=creds)

        # Cho phép override qua ENV, mặc định giữ nguyên
        SPREADSHEET_ID = os.environ.get("SPREADSHEET_ID", "YOUR_SHEET_ID")
        RANGE_NAME = os.environ.get("RANGE_NAME", "A1:B5")

        # Caching
        cache_ttl = int(os.environ.get("CACHE_TTL", "60"))
        key = _cache_key(SPREADSHEET_ID, RANGE_NAME)
        cached = cache_get(key)
        if cached is not None:
            _write_status_json({
                "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
                "sheet": SPREADSHEET_ID,
                "range": RANGE_NAME,
                "status": "Success (cache)",
                "retry": {"attempts_used": 0, "max_attempts": 0},
                "cache": {"ttl": cache_ttl, "source": "cache"}
            })
            return jsonify({"status": "success", "source": "cache", "data": cached})

        # Retry settings (ENV override)
        retries = int(os.environ.get("RETRY_MAX_ATTEMPTS", "3"))
        delay_ms = int(os.environ.get("RETRY_INITIAL_DELAY_MS", "500"))
        factor = float(os.environ.get("RETRY_BACKOFF_FACTOR", "1.7"))
        jitter_ms = int(os.environ.get("RETRY_JITTER_MS", "0"))

        attempts_used = 0

        def _fetch(attempt: int):
            nonlocal attempts_used
            attempts_used = attempt
            return service.spreadsheets().values().get(
                spreadsheetId=SPREADSHEET_ID,
                range=RANGE_NAME
            ).execute()

        result = retry(_fetch, retries=retries, delay_ms=delay_ms, factor=factor, jitter_ms=jitter_ms)
        values = result.get('values', [])
        cache_set(key, values, cache_ttl)

        # Status file
        _write_status_json({
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
            "sheet": SPREADSHEET_ID,
            "range": RANGE_NAME,
            "retry": {"attempts_used": attempts_used, "max_attempts": retries},
            "status": ("Retry success 100%" if attempts_used > 0 else "Success (no retry)"),
            "cache": {"ttl": cache_ttl, "source": "live"}
        })

        return jsonify({
            "status": "success",
            "source": "live",
            "attempts_used": attempts_used,
            "data": values
        })

    except Exception as e:
        _write_status_json({
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
            "status": "Retry failed",
            "error": str(e)
        })
        return jsonify({"status": "error", "message": str(e)})


@app.route('/health')
def health():
    return jsonify({"ok": True})


@app.route('/cache/clear')
def cache_clear():
    _CACHE.clear()
    return jsonify({"ok": True, "cleared": True})


# Cổng chạy cục bộ (cho test; Vercel auto handle)
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8080)

