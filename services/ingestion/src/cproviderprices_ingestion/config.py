from dataclasses import dataclass, field
from os import environ
from typing import Literal, cast

EnvironmentName = Literal["local", "test", "staging", "production"]


@dataclass(frozen=True, slots=True)
class Settings:
    environment: EnvironmentName
    service_name: str
    service_token: str = field(default="", repr=False)

    @classmethod
    def from_environment(cls) -> "Settings":
        environment = environ.get("INGESTION_ENV", "").strip()
        service_name = environ.get("INGESTION_SERVICE_NAME", "").strip()
        allowed_environments = {"local", "test", "staging", "production"}

        if environment not in allowed_environments:
            raise RuntimeError("INGESTION_ENV must be one of: local, test, staging, production.")
        if not service_name:
            raise RuntimeError("INGESTION_SERVICE_NAME is required.")

        service_token = environ.get("INGESTION_SERVICE_TOKEN", "").strip()
        if environment in {"staging", "production"} and not service_token:
            raise RuntimeError(
                "INGESTION_SERVICE_TOKEN is required outside local/test environments."
            )

        return cls(
            environment=cast(EnvironmentName, environment),
            service_name=service_name,
            service_token=service_token,
        )
