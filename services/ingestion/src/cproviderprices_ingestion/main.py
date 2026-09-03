from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from datetime import UTC, datetime
from typing import Literal

from fastapi import FastAPI
from pydantic import BaseModel

from cproviderprices_ingestion.config import Settings


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
    )

    @application.get("/health", response_model=HealthResponse)
    async def health() -> HealthResponse:
        active_settings: Settings = application.state.settings
        return HealthResponse(
            status="ok",
            service=active_settings.service_name,
            timestamp=datetime.now(UTC),
        )

    return application


app = create_app()
