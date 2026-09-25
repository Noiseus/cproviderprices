"""Pure deterministic extraction. No I/O, clock, database, tenant inference or matching."""

import re
from dataclasses import dataclass
from decimal import Context, Decimal, localcontext
from typing import Literal

from cproviderprices_ingestion.models import (
    Dimension,
    Measurement,
    NormalizedResult,
    Pack,
    RawRecord,
    Warning,
    WarningCode,
)


@dataclass(frozen=True)
class Unit:
    name: str
    dimension: Dimension
    canonical: Literal["g", "mL", "ea"]
    factor: str
    needs_us: bool = False


UNITS: dict[str, Unit] = {}
for aliases, definition in [
    (("g", "gram", "grams"), Unit("g", "weight", "g", "1")),
    (("kg", "kilogram", "kilograms"), Unit("kg", "weight", "g", "1000")),
    (("oz", "ounce", "ounces"), Unit("oz", "weight", "g", "28.349523125")),
    (("lb", "lbs", "pound", "pounds"), Unit("lb", "weight", "g", "453.59237")),
    (
        ("ml", "millilitre", "millilitres", "milliliter", "milliliters"),
        Unit("mL", "volume", "mL", "1"),
    ),
    (("l", "litre", "litres", "liter", "liters"), Unit("L", "volume", "mL", "1000")),
    (("gal",), Unit("gal", "volume", "mL", "3785.411784", True)),
    (("qt",), Unit("qt", "volume", "mL", "946.352946", True)),
    (("pt",), Unit("pt", "volume", "mL", "473.176473", True)),
    (("fl oz",), Unit("fl oz", "volume", "mL", "29.5735295625", True)),
    (("ea", "each", "count", "ct"), Unit("ea", "count", "ea", "1")),
]:
    for alias in aliases:
        UNITS[alias] = definition

# Match whole numeric tokens: never extract a valid suffix from a negative, fraction,
# locale decimal, range or exponent. Digits are ASCII, not Unicode digit classes.
NUMBER = r"[+-]?[0-9]+(?:[.,][0-9]+)*(?:[eE][+-]?[0-9]+)?(?:-[0-9]+(?:\.[0-9]+)?)?"
UNIT_TOKEN = (
    r"(?:(?:US(?:\s+LIQUID)?|IMPERIAL|DRY|TROY)\s+)?(?:FL\s+OZ|[A-Za-z]+|#)"
    r"(?:\s*\(?\s*(?:IMPERIAL|DRY|TROY)\)?)?"
)
PACK_RE = re.compile(
    rf"(?<![\w.,/+\-])(?P<count>{NUMBER})\s*/\s*(?P<size>{NUMBER})\s*"
    rf"(?P<unit>{UNIT_TOKEN})(?![\w/])",
    re.IGNORECASE,
)
SIZE_RE = re.compile(
    rf"(?<![\w.,/+\-])(?P<size>{NUMBER})\s*(?P<unit>{UNIT_TOKEN})(?![\w/])",
    re.IGNORECASE,
)
SIZE_CODE_RE = re.compile(r"(?<!\w)[0-9]+[Xx][0-9]+(?!\w)")
VARIABLE_RE = re.compile(r"\b(?:CW|CATCH\s+WEIGHT|VARIABLE\s+WEIGHT|RANDOM\s+WEIGHT)\b", re.I)
DECIMAL_RE = re.compile(r"[0-9]+(?:\.[0-9]+)?\Z")
MESSAGES: dict[WarningCode, str] = {
    "missing_quantity": "No supported quantitative value was supplied.",
    "ambiguous_quantity": "The numeric value is not an unambiguous positive decimal.",
    "ambiguous_unit": "The unit needs explicit source context.",
    "ambiguous_pack": "The packaging expression is incomplete or ambiguous.",
    "ambiguous_size_code": "The size token requires confirmed produce-size semantics.",
    "unit_system_required": "An explicit US liquid convention is required.",
    "unsupported_unit": "This unit is not supported by this parser version.",
    "unsafe_conversion": "The supplied unit is incompatible with the mapped dimension.",
    "conflicting_values": "Independent source values disagree; derived values are withheld.",
    "variable_weight": "Variable weight prevents fixed weight and case-total normalization.",
}


def decimal_text(value: Decimal) -> str:
    text = format(value, "f")
    return text.rstrip("0").rstrip(".") if "." in text else text


def numeric(text: str, integer: bool = False) -> Decimal | None:
    # Bound significant digits so all multiplication fits a private exact decimal context.
    if not DECIMAL_RE.fullmatch(text) or len(text) > 32 or (integer and "." in text):
        return None
    value = Decimal(text)
    return value if value > 0 else None


def normalize(record: RawRecord) -> NormalizedResult:
    # Ignore callers' precision/exponent limits. Bounded operands fit this exact context.
    with localcontext(Context(prec=100, Emax=999999, Emin=-999999)):
        return _normalize(record)


def _normalize(record: RawRecord) -> NormalizedResult:
    warnings: dict[tuple[str, WarningCode], Warning] = {}
    measurements: list[Measurement] = []
    packs: list[Pack] = []
    mapped = record.mapped
    conventions = record.source_conventions
    blocked: set[Dimension] = set()
    case_quantity: str | None = None

    def warn(code: WarningCode, field: str) -> None:
        warnings[field, code] = Warning(code=code, field=field, message=MESSAGES[code])

    def measure(
        text: str, value_text: str, unit_text: str, field: str, expected: Dimension | None = None
    ) -> Measurement | None:
        value = numeric(value_text)
        if value is None:
            warn("ambiguous_quantity", field)
            if expected:
                blocked.add(expected)
            return None
        token = " ".join(unit_text.lower().split())
        explicit_us = token.startswith("us ")
        if explicit_us:
            token = token.removeprefix("us ").removeprefix("liquid ")
        if token == "#":
            if expected == "weight" or conventions.pound_sign_is_weight:
                token = "lb"
            else:
                warn("ambiguous_unit", field)
        definition = UNITS.get(token)
        if definition is None:
            if token != "#":
                warn("unsupported_unit" if token else "ambiguous_unit", field)
            if expected:
                blocked.add(expected)
            return Measurement(
                field=field,
                original_text=text,
                original_value=value_text,
                original_unit=unit_text,
                value=decimal_text(value),
                unit=None,
                dimension=expected,
            )
        safe = True
        if expected is not None and definition.dimension != expected:
            warn("unsafe_conversion", field)
            blocked.update((expected, definition.dimension))
            safe = False
        if definition.needs_us and not (explicit_us or conventions.volume_system == "us_liquid"):
            warn("unit_system_required", field)
            safe = False
        return Measurement(
            field=field,
            original_text=text,
            original_value=value_text,
            original_unit=unit_text,
            value=decimal_text(value),
            unit=definition.name,
            dimension=definition.dimension,
            normalized_value=decimal_text(value * Decimal(definition.factor)) if safe else None,
            normalized_unit=definition.canonical if safe else None,
        )

    # Identical evidence is allowed; no source wins conflicts.
    for field, text in [
        ("description", mapped.description),
        ("pack_size_text", mapped.pack_size_text),
    ]:
        if not text:
            continue
        variable = VARIABLE_RE.search(text)
        if variable:
            warn("variable_weight", field)
        if re.search(r"(?<!\w)[0-9]+\s+[0-9]+\s*/|(?<!\w)[0-9]+\s+[Xx]\s+[0-9]", text):
            warn("ambiguous_pack", field)
            blocked.update(("weight", "volume", "count"))
        if re.search(r"[0-9]\s*(?:[-–—]|\bTO\b)\s*[0-9]|[-+]\s+[0-9]", text, re.I):
            warn("ambiguous_quantity", field)
            blocked.update(("weight", "volume", "count"))
        scrubbed = SIZE_CODE_RE.sub(lambda m: " " * len(m[0]), text)
        spans: list[tuple[int, int]] = []
        for match in PACK_RE.finditer(scrubbed):
            spans.append(match.span())
            count = numeric(match["count"], integer=True)
            item = measure(match[0], match["size"], match["unit"], field)
            if count is None or item is None:
                warn("ambiguous_pack", field)
                if item is not None and item.dimension:
                    blocked.add(item.dimension)
                continue
            packs.append(
                Pack(
                    field=field,
                    original_text=match[0],
                    original_count=match["count"],
                    pack_count=decimal_text(count),
                    unit_size=item,
                    case_total=decimal_text(count * Decimal(item.normalized_value))
                    if item.normalized_value is not None
                    else None,
                    case_total_unit=item.normalized_unit,
                )
            )
        remaining = list(scrubbed)
        for start, end in spans:
            remaining[start:end] = " " * (end - start)
        rest = "".join(remaining)
        # Any unconsumed slash indicates unsupported fraction/nested/incomplete packaging.
        if "/" in rest:
            warn("ambiguous_pack", field)
            blocked.update(("weight", "volume", "count"))
            continue
        for match in SIZE_RE.finditer(rest):
            # CS/CASE identify packaging, never a unit or an inferred count.
            if match["unit"].upper() in {"CS", "CASE"}:
                warn("ambiguous_unit", field)
                continue
            item = measure(match[0], match["size"], match["unit"], field)
            if item is not None:
                measurements.append(item)

    for field, text, expected in [
        ("weight", mapped.weight, "weight"),
        ("volume", mapped.volume, "volume"),
        ("quantity", mapped.quantity, "count"),
    ]:
        if not text:
            continue
        dimension: Dimension = (
            "weight" if expected == "weight" else ("volume" if expected == "volume" else "count")
        )
        value_text = text.strip()
        unit_text = (mapped.unit or "").strip()
        explicit_match = SIZE_RE.fullmatch(value_text)
        if explicit_match:
            value_text, unit_text = explicit_match["size"], explicit_match["unit"]
            if mapped.unit and mapped.unit.upper() not in {"CS", "CASE"}:
                separate = UNITS.get(" ".join(mapped.unit.lower().split()))
                embedded = UNITS.get(" ".join(unit_text.lower().split()))
                if (
                    separate is not None
                    and embedded is not None
                    and separate.dimension == dimension
                    and separate != embedded
                ):
                    warn("conflicting_values", field)
                    blocked.update((separate.dimension, embedded.dimension))
        # A quantity column without a unit records cases, not pack count/units per case.
        if dimension == "count" and (not unit_text or unit_text.upper() in {"CS", "CASE"}):
            case_count = numeric(value_text)
            if case_count is None:
                warn("ambiguous_quantity", field)
            else:
                case_quantity = decimal_text(case_count)
            continue
        item = measure(text, value_text, unit_text, field, dimension)
        if item is not None:
            measurements.append(item)

    # Compare measured sizes, not case quantities, in the same dimension using canonical values.
    all_sizes = measurements + [pack.unit_size for pack in packs]
    for dimension in ("weight", "volume", "count"):
        candidates = [item for item in all_sizes if item.dimension == dimension]
        values = {item.normalized_value for item in candidates if item.normalized_value is not None}
        counts = {pack.pack_count for pack in packs if pack.unit_size.dimension == dimension}
        same_unit_conflict = any(
            left.unit is not None and left.unit == right.unit and left.value != right.value
            for left in candidates
            for right in candidates
        )
        if len(values) > 1 or len(counts) > 1 or same_unit_conflict:
            blocked.add(dimension)
            for item in candidates:
                warn("conflicting_values", item.field)
        if len(counts) > 1:
            for pack in packs:
                if pack.unit_size.dimension == dimension:
                    warn("ambiguous_pack", pack.field)

    pack_dimensions = {pack.unit_size.dimension for pack in packs}
    if len(pack_dimensions) > 1:
        for pack in packs:
            warn("ambiguous_pack", pack.field)
            warn("unsafe_conversion", pack.field)
            if pack.unit_size.dimension:
                blocked.add(pack.unit_size.dimension)
    for field, text in [
        ("weight", mapped.weight),
        ("volume", mapped.volume),
        ("unit", mapped.unit),
        ("quantity", mapped.quantity),
    ]:
        if VARIABLE_RE.search(text or ""):
            warn("variable_weight", field)
    variable_weight = any(code == "variable_weight" for _, code in warnings)
    if variable_weight:
        blocked.add("weight")

    def suppress(item: Measurement) -> Measurement:
        if item.dimension in blocked:
            return item.model_copy(update={"normalized_value": None, "normalized_unit": None})
        return item

    measurements = [suppress(item) for item in measurements]
    packs = [
        pack.model_copy(
            update={
                "unit_size": suppress(pack.unit_size),
                "case_total": None
                if pack.unit_size.dimension in blocked or variable_weight
                else pack.case_total,
                "case_total_unit": None
                if pack.unit_size.dimension in blocked or variable_weight
                else pack.case_total_unit,
            }
        )
        for pack in packs
    ]

    size_codes: set[str] = set()
    for field, text in [
        ("description", mapped.description),
        ("pack_size_text", mapped.pack_size_text),
    ]:
        for match in SIZE_CODE_RE.finditer(text or ""):
            size_codes.add(match[0])
            if not conventions.produce_size_code or len(size_codes) > 1:
                warn("ambiguous_size_code", field)
    size_code = (
        next(iter(size_codes)) if len(size_codes) == 1 and conventions.produce_size_code else None
    )
    safe = case_quantity is not None or any(
        item.normalized_value is not None for item in measurements
    )
    safe = safe or any(pack.unit_size.normalized_value is not None for pack in packs)
    if not measurements and not packs and case_quantity is None:
        warn("missing_quantity", "record")
    codes = {code for _, code in warnings}
    confidence: Literal["high", "medium", "low"] = "high"
    if not safe or codes & {"conflicting_values", "unsafe_conversion", "variable_weight"}:
        confidence = "low"
    elif warnings:
        confidence = "medium"
    return NormalizedResult(
        record_id=record.record_id,
        correlation_id=record.correlation_id,
        original=record,
        measurements=measurements,
        packs=packs,
        case_quantity=case_quantity,
        size_code=size_code,
        variable_weight=variable_weight,
        warnings=[warnings[key] for key in sorted(warnings)],
        confidence=confidence,
    )
