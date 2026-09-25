"""Reproducible internal OpenAPI artifact for Phase D; no service startup or API calls."""

import argparse
import json
from pathlib import Path

from cproviderprices_ingestion.main import create_app


def contract_text() -> str:
    return json.dumps(create_app().openapi(), indent=2, sort_keys=True, ensure_ascii=True) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    arguments = parser.parse_args()
    path = Path(__file__).resolve().parents[2] / "openapi.json"
    expected = contract_text()
    if arguments.check:
        if not path.exists() or path.read_text(encoding="utf-8") != expected:
            raise SystemExit("Internal OpenAPI drift: regenerate the reviewed contract.")
        print("Internal OpenAPI contract matches typed service models.")
    else:
        path.write_text(expected, encoding="utf-8")
        print("Internal OpenAPI contract generated.")


if __name__ == "__main__":
    main()
