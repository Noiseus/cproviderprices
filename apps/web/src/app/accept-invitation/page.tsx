import { AuthShell } from "@/components/AuthShell";
import type { ApiErrorBody } from "@/lib/api";
import { apiServerBaseUrl } from "@/lib/server-api";
import { InvitationForm } from "./InvitationForm";

type InvitationResponse = {
  invitation: {
    intended_email: string;
    intended_role: string;
    organization: { name: string; slug: string };
  };
};

const stateMessages: Record<string, string> = {
  invitation_expired:
    "This invitation has expired. Ask an organization owner for a new invitation.",
  invitation_already_used:
    "This invitation has already been used. Sign in with the associated account.",
  organization_suspended:
    "This organization is suspended. Its invitation cannot be accepted right now.",
  invitation_unavailable: "This invitation is no longer available.",
};

export default async function AcceptInvitationPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string }>;
}) {
  const { token } = await searchParams;
  if (!token)
    return (
      <AuthShell title="Accept invitation">
        <p role="alert">This invitation link is invalid.</p>
      </AuthShell>
    );

  const response = await fetch(
    `${apiServerBaseUrl}/api/v1/auth/invitations/${encodeURIComponent(token)}`,
    { cache: "no-store", headers: { Accept: "application/json" } },
  );
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ApiErrorBody;
    const code = body.error?.code ?? "invitation_invalid";
    return (
      <AuthShell title="Invitation unavailable">
        <p role="alert">
          {stateMessages[code] ?? "This invitation link is invalid."}
        </p>
      </AuthShell>
    );
  }
  const body = (await response.json()) as InvitationResponse;
  return (
    <AuthShell title="Accept invitation">
      <InvitationForm token={token} invitation={body.invitation} />
    </AuthShell>
  );
}
