import { cookies } from "next/headers";
import { apiServerBaseUrl } from "./server-api";

export type OrganizationSession = {
  id: string;
  name: string;
  slug: string;
  status: "active" | "suspended" | "closed";
  role: "organization_owner" | "buyer" | "viewer";
};

export type SessionPayload = {
  session: {
    user: {
      id: string;
      name: string;
      email: string;
      email_verified: boolean;
      platform_role: "platform_admin" | null;
    };
    organizations: OrganizationSession[];
  };
};

export async function currentSession(): Promise<SessionPayload | null> {
  const cookieStore = await cookies();
  const response = await fetch(`${apiServerBaseUrl}/api/v1/auth/session`, {
    headers: { Accept: "application/json", Cookie: cookieStore.toString() },
    cache: "no-store",
  });

  if (!response.ok) return null;
  return (await response.json()) as SessionPayload;
}
