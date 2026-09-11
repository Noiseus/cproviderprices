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

export type SessionResult =
  | { kind: "authenticated"; data: SessionPayload }
  | {
      kind: "unauthenticated" | "forbidden" | "offline" | "unexpected";
      data: null;
    };

export async function currentSessionResult(): Promise<SessionResult> {
  try {
    const cookieStore = await cookies();
    const response = await fetch(`${apiServerBaseUrl}/api/v1/auth/session`, {
      headers: { Accept: "application/json", Cookie: cookieStore.toString() },
      cache: "no-store",
    });
    if (response.status === 401) return { kind: "unauthenticated", data: null };
    if (response.status === 403) return { kind: "forbidden", data: null };
    if (!response.ok) return { kind: "unexpected", data: null };
    return {
      kind: "authenticated",
      data: (await response.json()) as SessionPayload,
    };
  } catch {
    return { kind: "offline", data: null };
  }
}

export async function currentSession(): Promise<SessionPayload | null> {
  const result = await currentSessionResult();
  return result.kind === "authenticated" ? result.data : null;
}
