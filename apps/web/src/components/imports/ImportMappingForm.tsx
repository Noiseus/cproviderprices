"use client";

import { useState, type FormEvent } from "react";
import { Button, Checkbox, Input, Select } from "@/components/ui";
import {
  mappingErrors,
  mappingFields,
  type ImportDetail,
  type ImportMapping,
  type ImportTemplates,
  type ImportConventions,
  type MappingRequest,
} from "@/lib/imports";

export function ImportMappingForm({
  detail,
  templates,
  pending,
  onSave,
  serverErrors,
}: {
  detail: ImportDetail;
  templates: ImportTemplates["templates"];
  pending: boolean;
  onSave: (request: MappingRequest) => Promise<boolean>;
  serverErrors: Record<string, string>;
}) {
  const [mapping, setMapping] = useState<Partial<ImportMapping>>(
    detail.import.mapping_snapshot?.mapping ?? {},
  );
  const [conventions, setConventions] = useState<ImportConventions>(
    detail.import.mapping_snapshot?.source_conventions ?? {},
  );
  const [template, setTemplate] = useState("");
  const [name, setName] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const compatible = templates.filter(
    (item) =>
      JSON.stringify(item.headers) === JSON.stringify(detail.file.headers),
  );
  async function submit(event: FormEvent) {
    event.preventDefault();
    const issues = mappingErrors(mapping, detail.file.headers);
    setErrors(issues);
    if (Object.keys(issues).length) return;
    await onSave({
      mapping: mapping as ImportMapping,
      source_conventions: conventions,
      ...(name.trim() ? { template_name: name.trim() } : {}),
    });
  }
  return (
    <form onSubmit={(event) => void submit(event)} className="import-form">
      <p>
        Choose the source column for each internal field. Values are preserved;
        no normalization happens in your browser.
      </p>
      <Select
        label="Reusable mapping template"
        value={template}
        disabled={pending}
        onChange={(event) => {
          const id = event.target.value;
          setTemplate(id);
          const chosen = compatible.find((item) => item.id === id);
          if (chosen) {
            setMapping(chosen.mapping);
            setConventions(chosen.source_conventions);
            setErrors({});
          }
        }}
      >
        <option value="">Map columns manually</option>
        {compatible.map((item) => (
          <option key={item.id} value={item.id}>
            {item.name}
          </option>
        ))}
      </Select>
      <div className="import-mapping-grid">
        {mappingFields.map(([field, label]) => (
          <Select
            key={field}
            label={`${label}${field === "supplier_sku" || field === "description" ? " (required)" : ""}`}
            value={mapping[field] ?? ""}
            disabled={pending}
            error={errors[field] ?? serverErrors[`mapping.${field}`]}
            onChange={(event) => {
              const value = event.target.value;
              setMapping((old) => {
                return value
                  ? { ...old, [field]: value }
                  : Object.fromEntries(
                      Object.entries(old).filter(([key]) => key !== field),
                    );
              });
              setTemplate("");
            }}
          >
            <option value="">Not mapped</option>
            {detail.file.headers.map((header) => (
              <option
                key={header}
                value={header}
                disabled={Object.entries(mapping).some(
                  ([key, value]) => key !== field && value === header,
                )}
              >
                {header}
              </option>
            ))}
          </Select>
        ))}
      </div>
      <fieldset disabled={pending}>
        <legend>Explicit source conventions</legend>
        <p>Enable only when confirmed for this supplier file.</p>
        <Checkbox
          label="Volumes use US liquid units"
          checked={conventions.volume_system === "us_liquid"}
          onChange={(e) =>
            setConventions((old) => {
              const next = { ...old };
              if (e.target.checked) next.volume_system = "us_liquid";
              else delete next.volume_system;
              return next;
            })
          }
        />
        <Checkbox
          label="Produce size codes (such as 5X6)"
          checked={conventions.produce_size_code ?? false}
          onChange={(e) =>
            setConventions({
              ...conventions,
              produce_size_code: e.target.checked,
            })
          }
        />
        <Checkbox
          label="Pound sign means weight"
          checked={conventions.pound_sign_is_weight ?? false}
          onChange={(e) =>
            setConventions({
              ...conventions,
              pound_sign_is_weight: e.target.checked,
            })
          }
        />
      </fieldset>
      <Input
        label="Save reusable template as (optional)"
        value={name}
        maxLength={100}
        disabled={pending}
        error={serverErrors.template_name}
        onChange={(e) => setName(e.target.value)}
      />
      <Button type="submit" disabled={pending}>
        {pending ? "Saving…" : "Save mapping"}
      </Button>
    </form>
  );
}
