import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import UTC, datetime
from typing import Literal

from fastapi import Depends, FastAPI, Request, Response
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from fastapi.security import HTTPBearer
from pydantic import BaseModel

from cproviderprices_ingestion.boundary import NormalizationBoundary
from cproviderprices_ingestion.config import Settings
from cproviderprices_ingestion.models import BatchRequest, BatchResponse, ErrorEnvelope
from cproviderprices_ingestion.normalization import normalize


class HealthResponse(BaseModel):
    status: Literal["ok"]
    service: str
    timestamp: datetime


def create_app(settings: Settings | None = None) -> FastAPI:
    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        app.state.settings = settings or Settings.from_environment()
        yield

    application = FastAPI(
        title="CProviderPrices Ingestion",
        version="0.1.0",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
        lifespan=lifespan,
        servers=[{"url": "/", "description": "Internal ingestion service only"}],
    )
    application.add_middleware(NormalizationBoundary)

    @application.exception_handler(RequestValidationError)
    async def invalid_request(request: Request, error: RequestValidationError) -> JSONResponse:
        # Pydantic's default errors include input values; never expose them here.
        return JSONResponse(
            status_code=422,
            content={
                "error": {
                    "code": "invalid_request",
                    "request_id": request.state.request_id,
                }
            },
            headers={"X-Request-ID": request.state.request_id, "Cache-Control": "no-store"},
        )

    @application.post(
        "/internal/v1/normalize",
        response_model=BatchResponse,
        dependencies=[Depends(HTTPBearer(auto_error=False))],
        responses={status: {"model": ErrorEnvelope} for status in [400, 401, 413, 415, 422]},
        description="Internal only. Maximum 100 records and 1 MiB JSON body; no database access. "
        "Body correlation_id is authoritative on success; X-Request-ID is an optional error trace. "
        "Warnings count affected records, not warning objects. Retries belong to Laravel.",
    )
    async def normalize_batch(batch: BatchRequest, response: Response) -> BatchResponse:
        results = [normalize(record) for record in batch.records]
        warning = sum(bool(result.warnings) for result in results)
        logging.getLogger("cproviderprices.normalization").info(
            "normalization_batch processed=%d warning=%d failed=0",
            len(results),
            warning,
        )
        response.headers["X-Request-ID"] = batch.correlation_id
        response.headers["Cache-Control"] = "no-store"
        return BatchResponse(
            correlation_id=batch.correlation_id,
            results=results,
            processed=len(results),
            warning=warning,
            failed=0,
        )

    @application.get("/health", response_model=HealthResponse, openapi_extra={"security": []})
    async def health() -> HealthResponse:
        active_settings: Settings = application.state.settings
        return HealthResponse(
            status="ok",
            service=active_settings.service_name,
            timestamp=datetime.now(UTC),
        )

    return application


app = create_app()
