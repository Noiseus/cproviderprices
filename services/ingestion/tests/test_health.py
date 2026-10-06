from datetime import datetime

from fastapi.testclient import TestClient
from pytest import MonkeyPatch, raises

from cproviderprices_ingestion.config import Settings
from cproviderprices_ingestion.main import create_app


def test_health_returns_typed_utc_response() -> None:
    app = create_app(Settings(environment="test", service_name="ingestion"))

    with TestClient(app) as client:
        response = client.get("/health")

    assert response.status_code == 200
    payload = response.json()
    assert payload["status"] == "ok"
    assert payload["service"] == "ingestion"
    utc_offset = datetime.fromisoformat(payload["timestamp"]).utcoffset()
    assert utc_offset is not None
    assert utc_offset.total_seconds() == 0


def test_settings_reject_missing_environment(monkeypatch: MonkeyPatch) -> None:
    monkeypatch.delenv("INGESTION_ENV", raising=False)
    monkeypatch.setenv("INGESTION_SERVICE_NAME", "ingestion")

    with raises(RuntimeError, match="INGESTION_ENV"):
        Settings.from_environment()


def test_settings_require_service_token_outside_local_and_test(monkeypatch: MonkeyPatch) -> None:
    monkeypatch.setenv("INGESTION_ENV", "staging")
    monkeypatch.setenv("INGESTION_SERVICE_NAME", "ingestion")
    monkeypatch.delenv("INGESTION_SERVICE_TOKEN", raising=False)

    with raises(RuntimeError, match="INGESTION_SERVICE_TOKEN"):
        Settings.from_environment()
