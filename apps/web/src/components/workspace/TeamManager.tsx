"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { TeamInvitation, TeamMember } from "@/lib/customer-api";
import { Badge, Button, Card, Input, Select, Toast } from "@/components/ui";

type Team = { members: TeamMember[]; pending_invitations: TeamInvitation[] };

export function TeamManager() {
  const { organization } = useWorkspace();
  const owner = organization.role === "organization_owner";
  const [team, setTeam] = useState<Team | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const path = `/api/v1/organizations/${organization.id}`;

  async function reload() {
    try {
      const result = await apiRequest<{ team: Team }>(`${path}/team`);
      setTeam(result.team);
      setError(null);
    } catch (caught) {
      setError(
        caught instanceof Error ? caught.message : "Team could not be loaded.",
      );
    }
  }
  useEffect(() => void reload(), [path]);

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const role = String(data.get("role"));
    if (
      role === "organization_owner" &&
      !window.confirm(
        "Invite this person as an owner? Owners can administer the workspace and team.",
      )
    )
      return;
    setBusy(true);
    try {
      await apiRequest(`${path}/invitations`, {
        method: "POST",
        body: JSON.stringify({
          email: data.get("email"),
          role,
          high_risk_confirmed: role === "organization_owner",
        }),
      });
      form.reset();
      await reload();
    } catch (caught) {
      setError(message(caught));
    } finally {
      setBusy(false);
    }
  }

  async function mutate(url: string, init: RequestInit) {
    setBusy(true);
    try {
      await apiRequest(url, init);
      await reload();
    } catch (caught) {
      setError(message(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="workspace-page">
      <header>
        <p className="eyebrow">Workspace configuration</p>
        <h1>Team</h1>
        <p>Manage active members and pending invitations.</p>
      </header>
      {error && (
        <Toast title="Team action failed" tone="danger">
          {error}
        </Toast>
      )}
      {owner && (
        <Card>
          <h2>Invite a team member</h2>
          <form className="platform-form" onSubmit={invite}>
            <Input label="Email" name="email" type="email" required />
            <Select label="Role" name="role" defaultValue="buyer">
              <option value="buyer">Buyer</option>
              <option value="viewer">Viewer</option>
              <option value="organization_owner">
                Organization owner (high risk)
              </option>
            </Select>
            <Button type="submit" disabled={busy}>
              {busy ? "Working…" : "Send invitation"}
            </Button>
          </form>
        </Card>
      )}
      {!owner && (
        <Toast title="Read-only access" tone="neutral">
          Only organization owners can change team access.
        </Toast>
      )}
      <Card>
        <h2>Active members</h2>
        {!team ? (
          <p>Loading…</p>
        ) : (
          team.members.map((member) => (
            <div className="ui-list-row" key={member.id}>
              <div>
                <strong>{member.name}</strong>
                <p>{member.email}</p>
              </div>
              <Badge>{member.role.replaceAll("_", " ")}</Badge>
              {owner && member.id !== undefined && (
                <div>
                  <Select
                    aria-label={`Role for ${member.name}`}
                    label="Role"
                    value={member.role}
                    disabled={busy}
                    onChange={(e) =>
                      void mutate(`${path}/memberships/${member.id}`, {
                        method: "PATCH",
                        body: JSON.stringify({ role: e.target.value }),
                      })
                    }
                  >
                    <option value="organization_owner">Owner</option>
                    <option value="buyer">Buyer</option>
                    <option value="viewer">Viewer</option>
                  </Select>
                  <Button
                    variant="danger"
                    disabled={busy}
                    onClick={() =>
                      window.confirm(`Revoke ${member.name}'s access?`) &&
                      void mutate(`${path}/memberships/${member.id}`, {
                        method: "DELETE",
                      })
                    }
                  >
                    Revoke
                  </Button>
                </div>
              )}
            </div>
          ))
        )}
      </Card>
      <Card>
        <h2>Pending invitations</h2>
        {team?.pending_invitations.length === 0 && (
          <p>No pending invitations.</p>
        )}
        {team?.pending_invitations.map((invitation) => (
          <div className="ui-list-row" key={invitation.id}>
            <div>
              <strong>{invitation.intended_email}</strong>
              <p>{invitation.intended_role.replaceAll("_", " ")}</p>
            </div>
            {owner && (
              <div>
                <Button
                  variant="secondary"
                  disabled={busy}
                  onClick={() =>
                    void mutate(`${path}/invitations/${invitation.id}/resend`, {
                      method: "POST",
                    })
                  }
                >
                  Resend
                </Button>
                <Button
                  variant="danger"
                  disabled={busy}
                  onClick={() =>
                    void mutate(`${path}/invitations/${invitation.id}`, {
                      method: "DELETE",
                    })
                  }
                >
                  Cancel
                </Button>
              </div>
            )}
          </div>
        ))}
      </Card>
    </div>
  );
}

function message(error: unknown) {
  return error instanceof ApiClientError
    ? error.message
    : "The action could not be completed.";
}
