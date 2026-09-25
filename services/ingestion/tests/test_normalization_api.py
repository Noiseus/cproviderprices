import asyncio
import json
from collections.abc import Iterator
from pathlib import Path
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient
from pytest import LogCaptureFixture
from starlette.types import Message, Receive, Scope, Send

from cproviderprices_ingestion.boundary import NormalizationBoundary
from cproviderprices_ingestion.config import Settings
from cproviderprices_ingestion.export_contract import contract_text
from cproviderprices_ingestion.main import create_app
from cproviderprices_ingestion.models import BatchRequest, MappedValues, RawRecord

TOKEN = "synthetic-test-token-not-a-deployment-secret"
AUTH = {"Authorization": "Bearer " + TOKEN}
PATH = "/internal/v1/normalize"


def payload(size: int = 1) -> dict[str, object]:
    return BatchRequest(
        correlation_id="batch-1",
        records=[
            RawRecord(
                record_id=f"row-{i}",
                correlation_id="trace-1",
                mapped=MappedValues(supplier_sku="0001", description="MOZZ 4/5 LB"),
            )
            for i in range(size)
        ],
    ).model_dump()


@pytest.fixture
def client() -> Iterator[TestClient]:
    with TestClient(
        create_app(Settings(environment="test", service_name="ingestion", service_token=TOKEN))
    ) as test_client:
        yield test_client


def test_valid_authenticated_request_propagates_trace_and_version(client: TestClient) -> None:
    response = client.post(PATH, json=payload(), headers=AUTH)
    assert response.status_code == 200
    body = response.json()
    assert body["parser_version"] == "1.0.0"
    assert body["correlation_id"] == "batch-1"
    assert response.headers["X-Request-ID"] == "batch-1"
    assert body["results"][0]["correlation_id"] == "trace-1"
    assert body["results"][0]["original"]["mapped"]["supplier_sku"] == "0001"
    assert body["results"][0]["packs"][0]["case_total"] == "9071.8474"
    assert body["processed"] == 1 and body["warning"] == 0 and body["failed"] == 0
    assert client.post(PATH, json=payload(), headers=AUTH).content == response.content


@pytest.mark.parametrize("authorization", ["", "Bearer wrong", TOKEN, "Basic wrong"])
def test_missing_invalid_credentials_are_rejected(client: TestClient, authorization: str) -> None:
    response = client.post(PATH, json=payload(), headers={"Authorization": authorization})
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "unauthorized_service"
    assert TOKEN not in response.text


def test_empty_server_configuration_fails_closed_without_breaking_health() -> None:
    with TestClient(create_app(Settings(environment="test", service_name="ingestion"))) as client:
        assert client.post(PATH, json=payload(), headers=AUTH).status_code == 401
        assert client.get("/health").status_code == 200
        assert "token" not in client.get("/health").text


def test_maximum_and_oversized_batches(client: TestClient) -> None:
    valid = payload(100)
    assert client.post(PATH, json=valid, headers=AUTH).status_code == 200
    records = valid["records"]
    assert isinstance(records, list)
    records.append(records[0])
    response = client.post(PATH, json=valid, headers=AUTH)
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "invalid_request"


@pytest.mark.parametrize(
    "data",
    [
        {},
        {"correlation_id": "batch-1", "records": []},
        {"correlation_id": "batch-1", "records": [{"record_id": "row", "mapped": {}}]},
        {
            "correlation_id": "batch-1",
            "records": [
                {
                    "record_id": "row",
                    "mapped": {"supplier_sku": 123, "description": "PRIVATE_INPUT"},
                }
            ],
        },
        {
            "correlation_id": "batch-1",
            "records": [
                {
                    "record_id": "row",
                    "mapped": {
                        "supplier_sku": "1",
                        "description": "PRIVATE_INPUT",
                        "secret": "PRIVATE_SECRET",
                    },
                }
            ],
        },
    ],
)
def test_invalid_structure_types_fields_are_safe(
    client: TestClient, data: dict[str, object]
) -> None:
    response = client.post(PATH, json=data, headers={**AUTH, "X-Request-ID": "trace-error"})
    assert response.status_code == 422
    assert response.json() == {"error": {"code": "invalid_request", "request_id": "trace-error"}}
    assert "PRIVATE" not in response.text


def test_malformed_json(client: TestClient) -> None:
    response = client.post(
        PATH, content=b'{"PRIVATE_SOURCE":', headers={**AUTH, "Content-Type": "application/json"}
    )
    assert response.status_code == 422
    assert "PRIVATE_SOURCE" not in response.text


def test_body_limit_checks_actual_chunks_not_only_content_length(client: TestClient) -> None:
    def chunks() -> Iterator[bytes]:
        yield b" " * 600000
        yield b" " * 600000

    for headers in [{}, {"Content-Length": "1"}, {"Content-Length": "1048577"}]:
        response = client.post(
            PATH, content=chunks(), headers={**AUTH, "Content-Type": "application/json", **headers}
        )
        assert response.status_code == 413
        assert response.json()["error"]["code"] == "request_too_large"


def test_unsupported_media_and_encoding(client: TestClient) -> None:
    assert client.post(PATH, content="hello", headers=AUTH).status_code == 415
    assert (
        client.post(PATH, json=payload(), headers={**AUTH, "Content-Encoding": "gzip"}).status_code
        == 415
    )


def test_logs_contain_aggregate_counts_not_rows_or_credentials(
    client: TestClient,
    caplog: LogCaptureFixture,
) -> None:
    data = payload(2)
    records = data["records"]
    assert isinstance(records, list)
    records[1]["mapped"]["description"] = "PRIVATE_SOURCE 6/1 GAL"
    with caplog.at_level("INFO", logger="cproviderprices.normalization"):
        response = client.post(PATH, json=data, headers=AUTH)
    assert response.status_code == 200
    assert response.json()["warning"] == 1
    assert response.json()["processed"] == 2
    assert "processed=2 warning=1 failed=0" in caplog.text
    assert "PRIVATE_SOURCE" not in caplog.text
    assert TOKEN not in caplog.text


def test_request_bound_does_not_allow_oversized_string(client: TestClient) -> None:
    data = json.dumps(payload()).replace("MOZZ 4/5 LB", "x" * 4097)
    assert (
        client.post(
            PATH, content=data, headers={**AUTH, "Content-Type": "application/json"}
        ).status_code
        == 422
    )


def test_internal_contract_has_no_drift() -> None:
    path = Path(__file__).resolve().parents[1] / "openapi.json"
    assert path.read_text(encoding="utf-8") == contract_text()


def test_duplicate_record_ids_and_blank_mapped_values_rejected(client: TestClient) -> None:
    data = payload(2)
    records = data["records"]
    assert isinstance(records, list)
    records[1]["record_id"] = records[0]["record_id"]
    assert client.post(PATH, json=data, headers=AUTH).status_code == 422
    data = payload()
    records = data["records"]
    assert isinstance(records, list)
    records[0]["mapped"]["description"] = "   "
    assert client.post(PATH, json=data, headers=AUTH).status_code == 422


def test_streaming_limit_stops_receiving_before_downstream_parser() -> None:
    messages: list[Message] = []
    received = 0

    async def downstream(scope: Scope, receive: Receive, send: Send) -> None:
        raise AssertionError("Oversized body must never reach FastAPI.")

    async def receive() -> Message:
        nonlocal received
        received += 1
        assert received <= 2
        return {"type": "http.request", "body": b"x" * 600000, "more_body": True}

    async def send(message: Message) -> None:
        messages.append(message)

    scope: Scope = {
        "type": "http",
        "path": PATH,
        "headers": [
            (b"authorization", ("Bearer " + TOKEN).encode()),
            (b"content-type", b"application/json"),
        ],
        "app": SimpleNamespace(
            state=SimpleNamespace(
                settings=Settings(environment="test", service_name="ingestion", service_token=TOKEN)
            )
        ),
    }
    asyncio.run(NormalizationBoundary(downstream)(scope, receive, send))
    assert received == 2
    assert messages[0]["status"] == 413


def test_pathological_json_fails_with_safe_error(client: TestClient) -> None:
    for data in ["[" * 2000 + "0" + "]" * 2000, '{"x":' + "1" * 5000 + "}"]:
        response = client.post(
            PATH, content=data, headers={**AUTH, "Content-Type": "application/json"}
        )
        assert response.status_code == 422
        assert response.json()["error"]["code"] == "invalid_request"
