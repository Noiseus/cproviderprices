"""Explicit container-only HTTP smoke, separate from network-free parser tests.

Run: docker compose exec -T ingestion python tests/container_smoke.py
Uses an ephemeral random process-only credential. Never edits .env or prints the token.
"""

import json
import os
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request


def main() -> None:
    token = secrets.token_urlsafe(32)
    environment = {**os.environ, "INGESTION_SERVICE_TOKEN": token}
    process = subprocess.Popen(
        [
            sys.executable,
            "-m",
            "uvicorn",
            "cproviderprices_ingestion.main:app",
            "--host",
            "127.0.0.1",
            "--port",
            "18001",
            "--no-access-log",
        ],
        env=environment,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        for _ in range(50):
            try:
                with urllib.request.urlopen("http://127.0.0.1:18001/health", timeout=1) as response:
                    assert response.status == 200
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError("Container HTTP service did not start.")
        data = json.dumps(
            {
                "correlation_id": "container-smoke",
                "records": [
                    {
                        "record_id": "synthetic-1",
                        "mapped": {"supplier_sku": "0001", "description": "MOZZ WHOLE MILK 4/5 LB"},
                    }
                ],
            }
        ).encode()
        request = urllib.request.Request(
            "http://127.0.0.1:18001/internal/v1/normalize",
            data=data,
            headers={"Content-Type": "application/json", "Authorization": "Bearer " + token},
        )
        with urllib.request.urlopen(request, timeout=5) as response:
            result = json.load(response)
            assert response.status == 200
            assert result["results"][0]["packs"][0]["case_total"] == "9071.8474"
            assert result["parser_version"] == "1.0.0"
            assert response.headers["X-Request-ID"] == "container-smoke"
        for authorization in [None, "Bearer invalid-test-token"]:
            request.remove_header("Authorization")
            if authorization is not None:
                request.add_header("Authorization", authorization)
            try:
                urllib.request.urlopen(request, timeout=5)
            except urllib.error.HTTPError as error:
                assert error.code == 401
            else:
                raise AssertionError("Missing or invalid authentication was accepted.")
        print("Container HTTP smoke: health 200; normalization 200; missing/invalid auth both 401.")
    finally:
        process.terminate()
        process.wait(timeout=5)


if __name__ == "__main__":
    main()
