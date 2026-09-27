import type { components } from "@cproviderprices/generated-api-client";
import { ApiClientError } from "./api";

export type ImportSummary = components["schemas"]["ImportSummary"];
export type ImportDetail = components["schemas"]["ImportDetailResponse"];
export type ImportList = components["schemas"]["ImportListResponse"];
export type ImportTemplates = components["schemas"]["ImportTemplateListResponse"];
export type ImportMapping = components["schemas"]["ImportMappingFields"];
export type ImportConventions = components["schemas"]["ImportSourceConventions"];
export type MappingRequest = components["schemas"]["ImportMappingRequest"];
export type ImportDownloads = components["schemas"]["ImportDownloadsResponse"];

export const mappingFields: [keyof ImportMapping, string][] = [
  ["supplier_sku", "Supplier SKU"], ["description", "Description"], ["brand", "Brand"],
  ["pack_size_text", "Pack / size"], ["quantity", "Count / quantity"], ["weight", "Weight"],
  ["volume", "Volume"], ["unit", "Unit"], ["price", "Price"], ["currency", "Currency"], ["gtin", "GTIN"],
];
export function terminal(status: ImportSummary["status"]): boolean {
  return ["completed", "partially_completed", "failed", "canceled"].includes(status);
}
export function shouldPoll(run: ImportSummary): boolean {
  return !terminal(run.status) && (run.status !== "needs_mapping" || (!!run.validation_started_at && !run.validation_completed_at));
}
export function mappingErrors(mapping: Partial<ImportMapping>, headers: string[]): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of ["supplier_sku", "description"] as const) {
    if (!mapping[field]) errors[field] = "Choose a source column.";
  }
  const used = Object.values(mapping).filter(Boolean);
  for (const [field, value] of Object.entries(mapping)) {
    if (value && !headers.includes(value)) errors[field] = "Choose a detected source column.";
    if (value && used.filter((column) => column === value).length > 1) errors[field] = "Each source column may be used only once.";
  }
  return errors;
}
export function importError(error: unknown): string {
  if (!(error instanceof ApiClientError)) return "The import service could not be reached. Try again.";
  if (error.status === 401) return "Your session expired. Sign in again to continue.";
  if (error.status === 403) return "This action is not available with your current permissions or subscription. Contact your administrator.";
  if (error.status === 404) return "This import is unavailable in this organization.";
  if (error.status >= 500) return "The import service is temporarily unavailable. Try again.";
  return error.message;
}
export function importFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiClientError) || error.code !== "validation_failed") return {};
  const fields = error.details?.fields ?? error.details?.errors ?? error.details;
  if (!fields || typeof fields !== "object") return {};
  return Object.fromEntries(Object.entries(fields).flatMap(([key, value]) => Array.isArray(value) && typeof value[0] === "string" ? [[key, value[0]]] : []));
}
