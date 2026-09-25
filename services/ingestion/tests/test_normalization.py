import json
import socket
from decimal import getcontext
from typing import NoReturn

import pytest
from pytest import MonkeyPatch

from cproviderprices_ingestion.models import MappedValues, RawRecord, SourceConventions
from cproviderprices_ingestion.normalization import normalize


@pytest.fixture(autouse=True)
def no_network(monkeypatch: MonkeyPatch) -> None:
    def forbidden(*args: object, **kwargs: object) -> NoReturn:
        raise AssertionError("Parser tests must never access the network.")

    monkeypatch.setattr(socket, "create_connection", forbidden)
    monkeypatch.setattr(socket.socket, "connect", forbidden)


def record(
    description: str, conventions: SourceConventions | None = None, **fields: str
) -> RawRecord:
    return RawRecord(
        record_id="row-2",
        correlation_id="trace-1",
        mapped=MappedValues(supplier_sku="00012", description=description, **fields),
        source_conventions=conventions or SourceConventions(),
    )


@pytest.mark.parametrize(
    ("description", "count", "total"),
    [
        ("TOM BEEF 5X6 1/25 LB CS", "1", "11339.80925"),
        ("CHIX BRST BNLS SKNLS 4/10 LB", "4", "18143.6948"),
        ("OIL CANOLA 6/1 GAL", "6", "22712.470704"),
        ("MOZZ WHOLE MILK 4/5 LB", "4", "9071.8474"),
    ],
)
def test_required_packs(description: str, count: str, total: str) -> None:
    result = normalize(
        record(description, SourceConventions(volume_system="us_liquid", produce_size_code=True))
    )
    assert result.packs[0].pack_count == count
    assert result.packs[0].case_total == total
    assert result.confidence == "high"
    assert result.warnings == []
    assert result.parser_version == "1.0.0"


def test_hash_context_never_invents_pack_or_case_total() -> None:
    ambiguous = normalize(record("TOM BEEFSTEAK 25#"))
    assert ambiguous.measurements[0].normalized_value is None
    assert ambiguous.confidence == "low"
    assert "ambiguous_unit" in {w.code for w in ambiguous.warnings}
    result = normalize(record("TOM BEEFSTEAK 25#", SourceConventions(pound_sign_is_weight=True)))
    assert result.measurements[0].normalized_value == "11339.80925"
    assert result.measurements[0].original_unit == "#"
    assert result.packs == []
    assert result.confidence == "high"
    explicit = normalize(record("Tomato", weight="25#"))
    assert explicit.measurements[0].normalized_value == "11339.80925"


def test_size_code_is_not_multiplication() -> None:
    result = normalize(record("TOM BEEF 5X6 1/25 LB CS"))
    assert result.size_code is None
    assert result.packs[0].pack_count == "1"
    assert result.confidence == "medium"
    assert [w.code for w in result.warnings] == ["ambiguous_size_code"]
    known = normalize(record("TOM BEEF 5X6 1/25 LB CS", SourceConventions(produce_size_code=True)))
    assert known.size_code == "5X6"


@pytest.mark.parametrize(
    ("text", "canonical", "unit"),
    [
        ("1 g", "1", "g"),
        ("1 kg", "1000", "g"),
        ("1 oz", "28.349523125", "g"),
        ("1 lb", "453.59237", "g"),
        ("1 mL", "1", "mL"),
        ("1 L", "1000", "mL"),
        ("1 US gal", "3785.411784", "mL"),
        ("1 US qt", "946.352946", "mL"),
        ("1 US pt", "473.176473", "mL"),
        ("1 US fl oz", "29.5735295625", "mL"),
        ("12 CT", "12", "ea"),
        ("12 each", "12", "ea"),
        ("2 pounds", "907.18474", "g"),
        ("2 ounces", "56.69904625", "g"),
        ("2 grams", "2", "g"),
        ("2 kilograms", "2000", "g"),
        ("2 litres", "2000", "mL"),
        ("2 milliliters", "2", "mL"),
        ("0.1 OZ", "2.8349523125", "g"),
    ],
)
def test_exact_conversions(text: str, canonical: str, unit: str) -> None:
    result = normalize(record(text))
    assert result.measurements[0].normalized_value == canonical
    assert result.measurements[0].normalized_unit == unit
    assert result.packs == []
    assert result.confidence == "high"


@pytest.mark.parametrize("unit", ["gal", "qt", "pt", "fl oz"])
def test_us_volume_requires_explicit_convention(unit: str) -> None:
    result = normalize(record("1 " + unit))
    assert result.measurements[0].normalized_value is None
    assert [w.code for w in result.warnings] == ["unit_system_required"]
    known = normalize(record("1 " + unit, SourceConventions(volume_system="us_liquid")))
    assert known.measurements[0].normalized_unit == "mL"


@pytest.mark.parametrize("text", ["4/", "4/10", "1.5/10 LB", "4/10/2 LB", "0/10 LB"])
def test_ambiguous_and_incomplete_pack(text: str) -> None:
    result = normalize(record(text))
    assert result.confidence == "low"
    assert "ambiguous_pack" in {w.code for w in result.warnings}
    assert all(p.case_total is None for p in result.packs)


@pytest.mark.parametrize("text", ["-1 LB", "0 LB", "1,5 LB", "1e3 LB", "10-20 LB"])
def test_malformed_numeric_tokens_are_never_partially_converted(text: str) -> None:
    result = normalize(record(text))
    assert result.confidence == "low"
    assert "ambiguous_quantity" in {w.code for w in result.warnings}
    assert not any(m.normalized_value for m in result.measurements)


@pytest.mark.parametrize("flag", ["CW", "CATCH WEIGHT", "VARIABLE WEIGHT", "RANDOM WEIGHT"])
def test_variable_weight_suppresses_fixed_weight_and_total(flag: str) -> None:
    result = normalize(record("CHIX 4/10 LB " + flag))
    assert result.variable_weight
    assert result.packs[0].case_total is None
    assert result.packs[0].unit_size.normalized_value is None
    assert result.confidence == "low"
    assert "variable_weight" in {w.code for w in result.warnings}


@pytest.mark.parametrize("unit", ["stone", "IMPERIAL gal", "DRY pt", "TROY oz"])
def test_unsupported_units_remain_unconverted(unit: str) -> None:
    result = normalize(record("2 " + unit))
    assert result.measurements[0].normalized_value is None
    assert "unsupported_unit" in {w.code for w in result.warnings}
    assert result.confidence == "low"


def test_conflicting_fields_preserve_both_and_suppress_dimension() -> None:
    source = record("CHIX 4/10 LB", weight="25", unit="LB", volume="1 L")
    result = normalize(source)
    assert result.original == source
    assert result.packs[0].case_total is None
    assert result.measurements[0].normalized_value is None
    assert result.measurements[1].normalized_value == "1000"
    assert "conflicting_values" in {w.code for w in result.warnings}
    assert result.confidence == "low"


def test_equivalent_units_do_not_conflict() -> None:
    result = normalize(record("4/1 LB", weight="16 oz"))
    assert not result.warnings
    assert result.packs[0].case_total == "1814.36948"


def test_competing_packs_are_never_silently_selected() -> None:
    result = normalize(record("4/10 LB OR 2/10 LB"))
    assert "ambiguous_pack" in {w.code for w in result.warnings}
    assert all(p.case_total is None for p in result.packs)
    assert result.confidence == "low"


def test_no_cross_dimension_conversion() -> None:
    result = normalize(record("4/10 LB", weight="1 L"))
    assert result.confidence == "low"
    assert "unsafe_conversion" in {w.code for w in result.warnings}
    assert all(m.normalized_value is None for m in result.measurements)
    assert result.packs[0].case_total is None


def test_case_quantity_is_not_pack_count() -> None:
    result = normalize(record("4/5 LB", quantity="2", unit="CS"))
    assert result.case_quantity == "2"
    assert result.packs[0].pack_count == "4"
    assert result.packs[0].case_total == "9071.8474"


def test_repeat_serialization_preserves_originals_and_ignores_decimal_context() -> None:
    source = record("  MOZZ WHOLE MILK 4/5 LB  ", brand=" Brand ", price="0048.00")
    before = source.model_dump_json()
    expected = normalize(source).model_dump_json()
    precision = getcontext().prec
    maximum_exponent = getcontext().Emax
    try:
        getcontext().prec = 2
        getcontext().Emax = 1
        assert all(normalize(source).model_dump_json() == expected for _ in range(20))
    finally:
        getcontext().prec = precision
        getcontext().Emax = maximum_exponent
    assert source.model_dump_json() == before
    result = json.loads(expected)
    assert result["original"]["mapped"]["supplier_sku"] == "00012"
    assert result["original"]["mapped"]["price"] == "0048.00"
    assert result["original"]["mapped"]["brand"] == " Brand "


def test_warnings_are_sorted_deduplicated_and_do_not_echo_source() -> None:
    result = normalize(record("PRIVATE 5X6 4/1 GAL 5X6"))
    keys = [(w.field, w.code) for w in result.warnings]
    assert keys == sorted(set(keys))
    assert all("PRIVATE" not in w.message for w in result.warnings)


@pytest.mark.parametrize("text", ["1 1/2 LB", "4 X 10 LB", "10 TO 20 LB", "10–20 LB", "- 5 LB"])
def test_no_valid_suffix_is_extracted_from_ambiguous_expressions(text: str) -> None:
    result = normalize(record(text))
    assert result.confidence == "low"
    assert all(m.normalized_value is None for m in result.measurements)
    assert all(p.case_total is None for p in result.packs)


@pytest.mark.parametrize("text", ["1 gal IMPERIAL", "1 oz (TROY)", "1 pt DRY"])
def test_unsupported_suffix_qualifiers_are_not_ignored(text: str) -> None:
    result = normalize(record(text, SourceConventions(volume_system="us_liquid")))
    assert "unsupported_unit" in {w.code for w in result.warnings}
    assert all(m.normalized_value is None for m in result.measurements)


def test_conflicts_are_visible_even_without_volume_convention() -> None:
    result = normalize(record("6/1 GAL", volume="2 GAL"))
    assert "conflicting_values" in {w.code for w in result.warnings}
    assert result.packs[0].case_total is None


def test_competing_pack_dimensions_are_unsafe() -> None:
    result = normalize(record("4/1 LB OR 4/1 L"))
    assert "unsafe_conversion" in {w.code for w in result.warnings}
    assert all(p.case_total is None for p in result.packs)


def test_explicit_unit_disagreement_does_not_choose_a_winner() -> None:
    result = normalize(record("4/1 LB", weight="1 LB", unit="kg"))
    assert "conflicting_values" in {w.code for w in result.warnings}
    assert result.packs[0].case_total is None
