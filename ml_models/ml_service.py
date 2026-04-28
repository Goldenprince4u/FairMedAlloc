"""
FairMedAlloc local ML scoring service
=====================================
Loopback-only HTTP API for batch urgency scoring.

Endpoints:
    GET  /health
    POST /ml/score-batch

Usage:
    py -3 ml_service.py
    py -3 ml_service.py 127.0.0.1 5051
"""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import logging
import os
import sys

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

import predict

logging.basicConfig(level=logging.WARNING)

DEFAULT_HOST = os.environ.get("FAIRMED_ML_HOST", "127.0.0.1")
DEFAULT_PORT = int(os.environ.get("FAIRMED_ML_PORT", "5051"))


def current_mode():
    return predict.describe_mode()


class MlRequestHandler(BaseHTTPRequestHandler):
    server_version = "FairMedAllocML/1.0"

    def do_GET(self):
        if self.path == "/health":
            self.send_json(
                200,
                {
                    "status": "success",
                    "service": "ml",
                    "mode": current_mode(),
                },
            )
            return

        self.send_json(404, {"status": "error", "message": "Not found"})

    def do_POST(self):
        if self.path != "/ml/score-batch":
            self.send_json(404, {"status": "error", "message": "Not found"})
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self.send_json(400, {"status": "error", "message": "Invalid Content-Length"})
            return

        raw_body = self.rfile.read(length) if length > 0 else b""
        try:
            payload = json.loads(raw_body.decode("utf-8") or "null")
        except json.JSONDecodeError:
            self.send_json(400, {"status": "error", "message": "Invalid JSON body"})
            return

        if not isinstance(payload, (dict, list)):
            self.send_json(400, {"status": "error", "message": "Expected an object or array payload"})
            return

        try:
            detailed_results = predict.process_batch_verbose(payload)
        except Exception as exc:
            logging.exception("Scoring request failed")
            self.send_json(500, {"status": "error", "message": str(exc)})
            return

        scores = {student_id: item["score"] for student_id, item in detailed_results.items()}
        tiers = {student_id: item["tier"] for student_id, item in detailed_results.items()}

        self.send_json(
            200,
            {
                "status": "success",
                "mode": predict.describe_mode(detailed_results),
                "results": scores,
                "tiers": tiers,
            },
        )

    def log_message(self, format, *args):
        logging.info("%s - %s", self.address_string(), format % args)

    def send_json(self, status_code, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main():
    host = DEFAULT_HOST
    port = DEFAULT_PORT

    if len(sys.argv) >= 2:
        host = sys.argv[1]
    if len(sys.argv) >= 3:
        port = int(sys.argv[2])

    predict.load_ml_model()
    server = ThreadingHTTPServer((host, port), MlRequestHandler)
    print(f"FairMedAlloc ML service listening on http://{host}:{port}")
    server.serve_forever()


if __name__ == "__main__":
    main()
