from flask import Flask, jsonify
from googleapiclient.discovery import build
from google.oauth2 import service_account
import os, json

app = Flask(__name__)

@app.route('/')
def home():
    try:
        # Load credentials từ biến môi trường Vercel
        creds_info = json.loads(os.environ["GOOGLE_APPLICATION_CREDENTIALS_JSON"])
        creds = service_account.Credentials.from_service_account_info(creds_info)

        # Kết nối Google Sheets API
        service = build('sheets', 'v4', credentials=creds)

        # 🧾 Thay sheet ID thật của bạn
        SPREADSHEET_ID = "YOUR_SHEET_ID"
        RANGE_NAME = "A1:B5"

        result = service.spreadsheets().values().get(
            spreadsheetId=SPREADSHEET_ID,
            range=RANGE_NAME
        ).execute()

        values = result.get('values', [])
        return jsonify({"status": "success", "data": values})

    except Exception as e:
        return jsonify({"status": "error", "message": str(e)})

# Cổng chạy cục bộ (chỉ để test, Vercel sẽ auto handle)
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8080)

