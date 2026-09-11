export type LocationOption = { id: string; name: string };

/**
 * Temporary Prompt 08 view model. IDs are namespaced to the already-authorized
 * tenant and are never sent to the API as authorization evidence.
 */
export function developmentLocationOptions(
  organizationId: string,
): LocationOption[] {
  return [
    { id: `${organizationId}:all`, name: "All locations" },
    {
      id: `${organizationId}:primary`,
      name: "Primary location · development fixture",
    },
  ];
}
