from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

PARSER_VERSION: Literal["1.0.0"] = "1.0.0"
Text = Annotated[str, Field(max_length=4096)]
Reference = Annotated[str, Field(min_length=1, max_length=128, pattern=r"^[A-Za-z0-9._:-]+$")]
Dimension = Literal["weight", "volume", "count"]
WarningCode = Literal[
    "missing_quantity",
    "ambiguous_quantity",
    "ambiguous_unit",
    "ambiguous_pack",
    "ambiguous_size_code",
    "unit_system_required",
    "unsupported_unit",
    "unsafe_conversion",
    "conflicting_values",
    "variable_weight",
]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True, frozen=True)


class SourceConventions(StrictModel):
    volume_system: Literal["us_liquid"] | None = None
    produce_size_code: bool = False
    pound_sign_is_weight: bool = False


class MappedValues(StrictModel):
    supplier_sku: Annotated[str, Field(min_length=1, max_length=4096)]
    description: Annotated[str, Field(min_length=1, max_length=4096)]
    brand: Text | None = None
    pack_size_text: Text | None = None
    quantity: Text | None = None
    weight: Text | None = None
    volume: Text | None = None
    unit: Text | None = None
    price: Text | None = None
    currency: Text | None = None
    observed_at: Text | None = None
    availability: Text | None = None
    gtin: Text | None = None

    @field_validator("supplier_sku", "description")
    @classmethod
    def required_text(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("Required mapped text is blank.")
        return value


class RawRecord(StrictModel):
    record_id: Reference
    correlation_id: Reference | None = None
    mapped: MappedValues
    source_conventions: SourceConventions = Field(default_factory=SourceConventions)


class Warning(StrictModel):
    code: WarningCode
    field: str
    message: str


class Measurement(StrictModel):
    field: str
    original_text: str
    original_value: str
    original_unit: str
    dimension: Dimension | None
    value: str
    unit: str | None
    normalized_value: str | None = None
    normalized_unit: Literal["g", "mL", "ea"] | None = None


class Pack(StrictModel):
    field: str
    original_text: str
    original_count: str
    pack_count: str
    unit_size: Measurement
    case_total: str | None = None
    case_total_unit: Literal["g", "mL", "ea"] | None = None


class NormalizedResult(StrictModel):
    parser_version: Literal["1.0.0"] = PARSER_VERSION
    record_id: str
    correlation_id: str | None
    original: RawRecord
    measurements: list[Measurement]
    packs: list[Pack]
    case_quantity: str | None
    size_code: str | None
    variable_weight: bool
    warnings: list[Warning]
    confidence: Literal["high", "medium", "low"]


class BatchRequest(StrictModel):
    correlation_id: Reference
    records: Annotated[list[RawRecord], Field(min_length=1, max_length=100)]

    @field_validator("records")
    @classmethod
    def unique_references(cls, records: list[RawRecord]) -> list[RawRecord]:
        if len({record.record_id for record in records}) != len(records):
            raise ValueError("Record references must be unique within a batch.")
        return records


class BatchResponse(StrictModel):
    parser_version: Literal["1.0.0"] = PARSER_VERSION
    correlation_id: str
    results: list[NormalizedResult]
    processed: int
    warning: int
    failed: int


class ServiceError(StrictModel):
    code: Literal[
        "unauthorized_service",
        "unsupported_encoding",
        "unsupported_media_type",
        "invalid_content_length",
        "request_too_large",
        "invalid_request",
    ]
    request_id: str


class ErrorEnvelope(StrictModel):
    error: ServiceError
