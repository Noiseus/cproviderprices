"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { TeamInvitation, TeamMember } from "@/lib/customer-api";
import { Badge, Button, Card, Input, Select, Toast } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";

type Team = { members: TeamMember[]; pending_invitations: TeamInvitation[] };

export function TeamManager() {
  const { organization } = useWorkspace();
  const { localizeError, t } = useTranslation();
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
        caught instanceof ApiClientError
          ? localizeError(caught.code)
          : localizeError("network_error"),
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
      !window.confirm(t("team.ownerInviteConfirm"))
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
        <p className="eyebrow">{t("locations.workspaceConfiguration")}</p>
        <h1>{t("team.title")}</h1>
        <p>{t("team.description")}</p>
      </header>
      {error && (
        <Toast title={t("team.teamActionFailed")} tone="danger">
          {error}
        </Toast>
      )}
      {owner && (
        <Card>
          <h2>{t("team.inviteMember")}</h2>
          <form className="platform-form" onSubmit={invite}>
            <Input
              label={t("common.email")}
              name="email"
              type="email"
              required
            />
            <Select label={t("common.role")} name="role" defaultValue="buyer">
              <option value="buyer">{t("roles.buyer")}</option>
              <option value="viewer">{t("roles.viewer")}</option>
              <option value="organization_owner">{t("team.ownerRisk")}</option>
            </Select>
            <Button type="submit" disabled={busy}>
              {busy ? t("common.working") : t("team.sendInvitation")}
            </Button>
          </form>
        </Card>
      )}
      {!owner && (
        <Toast title={t("common.readOnlyAccess")} tone="neutral">
          {t("team.readOnlyDescription")}
        </Toast>
      )}
      <Card>
        <h2>{t("team.activeMembers")}</h2>
        {!team ? (
          <p>{t("common.loading")}</p>
        ) : (
          team.members.map((member) => (
            <div className="ui-list-row" key={member.id}>
              <div>
                <strong>{member.name}</strong>
                <p>{member.email}</p>
              </div>
              <Badge>
                {t(
                  `team.roleBadge${member.role
                    .split("_")
                    .map((part) => part[0].toUpperCase() + part.slice(1))
                    .join("")}`,
                )}
              </Badge>
              {owner && member.id !== undefined && (
                <div>
                  <Select
                    aria-label={t("team.roleFor", { name: member.name })}
                    label={t("common.role")}
                    value={member.role}
                    disabled={busy}
                    onChange={(e) =>
                      void mutate(`${path}/memberships/${member.id}`, {
                        method: "PATCH",
                        body: JSON.stringify({ role: e.target.value }),
                      })
                    }
                  >
                    <option value="organization_owner">
                      {t("roles.organization_owner")}
                    </option>
                    <option value="buyer">{t("roles.buyer")}</option>
                    <option value="viewer">{t("roles.viewer")}</option>
                  </Select>
                  <Button
                    variant="danger"
                    disabled={busy}
                    onClick={() =>
                      window.confirm(
                        t("team.revokeAccess", { name: member.name }),
                      ) &&
                      void mutate(`${path}/memberships/${member.id}`, {
                        method: "DELETE",
                      })
                    }
                  >
                    {t("team.revoke")}
                  </Button>
                </div>
              )}
            </div>
          ))
        )}
      </Card>
      <Card>
        <h2>{t("team.pendingInvitations")}</h2>
        {team?.pending_invitations.length === 0 && (
          <p>{t("team.noPendingInvitations")}</p>
        )}
        {team?.pending_invitations.map((invitation) => (
          <div className="ui-list-row" key={invitation.id}>
            <div>
              <strong>{invitation.intended_email}</strong>
              <p>{t(`roles.${invitation.intended_role}`)}</p>
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
                  {t("team.resend")}
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
                  {t("common.cancel")}
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
