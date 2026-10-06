import type { Session } from "@/types/api";

export type SessionOrganization = Session["organizations"][number];

export interface WorkspaceLocation {
  id: string;
  organization_id: string;
  name: string;
  status: "active" | "inactive";
  timezone: string;
}

export function selectOrganization(
  session: Session,
  organizationId: string,
): SessionOrganization | null {
  return (
    session.organizations.find(
      (organization) => organization.id === organizationId,
    ) ?? null
  );
}

export function selectActiveLocation(
  organizationId: string,
  location: WorkspaceLocation,
): WorkspaceLocation | null {
  return location.organization_id === organizationId &&
    location.status === "active"
    ? location
    : null;
}
