import { cookies } from "next/headers";
import { apiServerBaseUrl } from "./server-api";

export type TeamMember = {
  id: string;
  name: string;
  email: string;
  role: "organization_owner" | "buyer" | "viewer";
  status: "active";
  joined_at: string | null;
};
export type TeamInvitation = {
  id: string;
  intended_email: string;
  intended_role: TeamMember["role"];
  status: string;
  expires_at: string;
};
export type Location = {
  id: string;
  name: string;
  code: string;
  status: "active" | "inactive";
  timezone: string;
  address_line_1: string | null;
  address_line_2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  country_code: string;
  external_reference: string | null;
};
export type SupplierDefinition = { id: string; code: string; name: string };
export type SupplierAccount = {
  id: string;
  supplier: SupplierDefinition;
  location: Pick<Location, "id" | "name">;
  display_name: string;
  masked_account_reference: string | null;
  source_method: "manual_import" | "future_automatic_sync";
  expected_refresh_cadence: "on_demand" | "daily" | "weekly" | "monthly";
  status: "active" | "inactive";
  last_successful_sync_at: string | null;
};

export async function tenantServerRequest<T>(organizationId: string, path: string): Promise<T> {
  const cookieStore = await cookies();
  const response = await fetch(
    `${apiServerBaseUrl}/api/v1/organizations/${organizationId}/${path}`,
    { headers: { Accept: "application/json", Cookie: cookieStore.toString() }, cache: "no-store" },
  );
  if (!response.ok) throw new Error("Tenant configuration is unavailable.");
  return (await response.json()) as T;
}
