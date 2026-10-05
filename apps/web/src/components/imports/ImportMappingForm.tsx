"use client";

import { useState, type FormEvent } from "react";
import { Button, Checkbox, Input, Select } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";
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
  const { t } = useTranslation();
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
      <p>{t("imports.mappingDescription")}</p>
      <Select
        label={t("imports.mappingTemplate")}
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
        <option value="">{t("imports.mapColumnsManually")}</option>
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
            label={`${label}${field === "supplier_sku" || field === "description" ? ` ${t("imports.requiredField")}` : ""}`}
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
            <option value="">{t("imports.notMapped")}</option>
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
        <legend>{t("imports.sourceConventions")}</legend>
        <p>{t("imports.conventionsHelp")}</p>
        <Checkbox
          label={t("imports.usLiquid")}
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
          label={t("imports.produceSize")}
          checked={conventions.produce_size_code ?? false}
          onChange={(e) =>
            setConventions({
              ...conventions,
              produce_size_code: e.target.checked,
            })
          }
        />
        <Checkbox
          label={t("imports.poundWeight")}
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
        label={t("imports.saveTemplate")}
        value={name}
        maxLength={100}
        disabled={pending}
        error={serverErrors.template_name}
        onChange={(e) => setName(e.target.value)}
      />
      <Button type="submit" disabled={pending}>
        {pending ? t("common.saving") : t("imports.saveMapping")}
      </Button>
    </form>
  );
}
