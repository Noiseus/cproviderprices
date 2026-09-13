"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { ApiClientError, apiRequest } from "@/lib/api";
import type {
  OrganizationStatus,
  PlatformInvitation,
  PlatformOrganizationDetail,
} from "@/lib/platform-api";
import type { SubscriptionStatusPayload } from "@/lib/server-subscription";
import {
  Badge,
  Button,
  Card,
  ConfirmationDialog,
  DataTableShell,
  Input,
  PageHeader,
  Select,
  Toast,
} from "../ui";

type ConfirmAction =
  | { kind: "organization"; status: OrganizationStatus; reason: string }
  | { kind: "subscription"; action: "suspend" | "cancel"; reason: string };

function tone(status: string): "success" | "warning" | "danger" | "neutral" {
  if (status === "active" || status === "trialing" || status === "accepted")
    return "success";
  if (status === "suspended" || status === "past_due" || status === "pending")
    return "warning";
  if (
    status === "closed" ||
    status === "canceled" ||
    status === "expired" ||
    status === "revoked"
  )
    return "danger";
  return "neutral";
}

export function PlatformOrganizationView({
  initial,
  returnHref,
}: {
  initial: PlatformOrganizationDetail;
  returnHref: string;
}) {
  const [organizationStatus, setOrganizationStatus] = useState(
    initial.organization.status,
  );
  const [subscription, setSubscription] = useState(initial.subscription_status);
  const [invitations, setInvitations] = useState(initial.invitations);
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [message, setMessage] = useState<{
    tone: "success" | "danger";
    text: string;
  } | null>(null);
  const organizationId = initial.organization.id;

  async function mutate<T>(
    key: string,
    path: string,
    init: RequestInit,
  ): Promise<T | null> {
    if (pendingAction) return null;
    setPendingAction(key);
    setMessage(null);
    try {
      return await apiRequest<T>(path, init);
    } catch (error) {
      setMessage({
        tone: "danger",
        text:
          error instanceof ApiClientError
            ? error.message
            : "The platform action failed.",
      });
      return null;
    } finally {
      setPendingAction(null);
    }
  }

  async function updateOrganization(
    status: OrganizationStatus,
    reason: string,
  ) {
    const response = await mutate<{
      organization_status: { status: OrganizationStatus };
    }>(
      "organization-status",
      `/api/v1/platform/organizations/${organizationId}/status`,
      { method: "PATCH", body: JSON.stringify({ status, reason }) },
    );
    if (response) {
      setOrganizationStatus(response.organization_status.status);
      setMessage({ tone: "success", text: "Organization status updated." });
      setConfirm(null);
    }
  }

  async function updateSubscription(
    action: string,
    values: { plan_code?: string; trial_ends_at?: string; reason?: string },
  ) {
    const response = await mutate<{
      subscription_status: SubscriptionStatusPayload;
    }>(
      `subscription-${action}`,
      `/api/v1/platform/organizations/${organizationId}/subscription`,
      { method: "PATCH", body: JSON.stringify({ action, ...values }) },
    );
    if (response) {
      setSubscription(response.subscription_status);
      setMessage({
        tone: "success",
        text: `Subscription ${action.replaceAll("_", " ")} completed.`,
      });
      setConfirm(null);
    }
  }

  async function submitOrganizationStatus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    const status = String(values.get("status")) as OrganizationStatus;
    const reason = String(values.get("reason"));
    setConfirm({ kind: "organization", status, reason });
  }

  async function submitSubscription(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    const action = String(values.get("action"));
    const reason = String(values.get("reason") ?? "");
    const rawTrialEnd = String(values.get("trial_ends_at") ?? "");
    if (action === "suspend" || action === "cancel") {
      setConfirm({ kind: "subscription", action, reason });
      return;
    }
    await updateSubscription(action, {
      plan_code: String(values.get("plan_code") ?? "") || undefined,
      trial_ends_at: rawTrialEnd
        ? new Date(`${rawTrialEnd}:00Z`).toISOString()
        : undefined,
      reason: reason || undefined,
    });
  }

  async function invite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const email = String(new FormData(form).get("email"));
    const response = await mutate<{ invitation: PlatformInvitation }>(
      "invite-owner",
      `/api/v1/platform/organizations/${organizationId}/initial-owner-invitations`,
      { method: "POST", body: JSON.stringify({ email }) },
    );
    if (response) {
      setInvitations((current) => [response.invitation, ...current]);
      setMessage({ tone: "success", text: "Initial-owner invitation sent." });
      form.reset();
    }
  }

  async function resend(invitation: PlatformInvitation) {
    const response = await mutate<{ invitation: PlatformInvitation }>(
      `resend-${invitation.id}`,
      `/api/v1/platform/organizations/${organizationId}/initial-owner-invitations/${invitation.id}/resend`,
      { method: "POST" },
    );
    if (response) {
      setInvitations((current) => [
        response.invitation,
        ...current.map((item) =>
          item.id === invitation.id
            ? { ...item, status: "revoked" as const }
            : item,
        ),
      ]);
      setMessage({ tone: "success", text: "Invitation replaced and resent." });
    }
  }

  const currentSubscription = subscription.subscription;
  const currentPlan = subscription.plan;

  return (
    <div className="platform-stack">
      <Link href={returnHref}>← Back to organizations</Link>
      <PageHeader
        eyebrow="Platform provisioning"
        title={initial.organization.name}
        description={`${initial.organization.slug} · Created ${new Date(initial.organization.created_at).toLocaleString("en-US", { timeZone: "UTC" })} UTC`}
        actions={
          <Badge tone={tone(organizationStatus)}>{organizationStatus}</Badge>
        }
      />
      {message && (
        <Toast
          title={
            message.tone === "success" ? "Action completed" : "Action failed"
          }
          tone={message.tone}
        >
          {message.text}
        </Toast>
      )}

      <div className="platform-detail-grid">
        <Card>
          <h2>Organization lifecycle</h2>
          <p>Organization lifecycle is independent from subscription access.</p>
          <form className="platform-form" onSubmit={submitOrganizationStatus}>
            <Select
              label="Status"
              name="status"
              defaultValue={organizationStatus}
              required
            >
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="closed">Closed</option>
            </Select>
            <Input
              label="Reason"
              name="reason"
              required
              minLength={3}
              maxLength={1000}
            />
            <Button type="submit" disabled={pendingAction !== null}>
              Review status change
            </Button>
          </form>
        </Card>
        <Card>
          <h2>Trial and subscription</h2>
          <p>
            <strong>{currentPlan?.name ?? "No plan"}</strong> ·{" "}
            <Badge tone={tone(currentSubscription?.status ?? "none")}>
              {currentSubscription?.status.replaceAll("_", " ") ??
                "not configured"}
            </Badge>
          </p>
          {currentSubscription?.trial_ends_at && (
            <p>
              Trial ends{" "}
              {new Date(currentSubscription.trial_ends_at).toLocaleString(
                "en-US",
                { timeZone: "UTC" },
              )}{" "}
              UTC
            </p>
          )}
          <form className="platform-form" onSubmit={submitSubscription}>
            <Select
              label="Action"
              name="action"
              required
              defaultValue={
                currentSubscription ? "extend_trial" : "start_trial"
              }
            >
              <option value="start_trial">Start/set trial</option>
              <option value="extend_trial">Extend trial</option>
              <option value="activate">Activate</option>
              <option value="suspend">Suspend</option>
              <option value="cancel">Cancel</option>
            </Select>
            <Select
              label="Plan"
              name="plan_code"
              defaultValue={
                currentPlan?.code ?? initial.available_plans[0]?.code ?? ""
              }
            >
              {initial.available_plans.map((plan) => (
                <option key={plan.code} value={plan.code}>
                  {plan.name}
                </option>
              ))}
            </Select>
            <Input
              label="Trial end (UTC)"
              name="trial_ends_at"
              type="datetime-local"
            />
            <Input
              label="Reason"
              name="reason"
              maxLength={1000}
              hint="Required for suspension and cancellation."
            />
            <Button type="submit" disabled={pendingAction !== null}>
              {pendingAction?.startsWith("subscription-")
                ? "Updating…"
                : "Apply subscription action"}
            </Button>
          </form>
        </Card>
      </div>

      <Card>
        <h2>Plan entitlements</h2>
        <ul className="capability-list">
          {Object.entries(subscription.limits).map(([key, value]) => (
            <li key={key}>
              <span>{key.replaceAll("_", " ")}</span>
              <strong>
                {value.current_usage === null || value.limit === null
                  ? "Not implemented"
                  : `${value.current_usage} of ${value.limit}`}
              </strong>
            </li>
          ))}
          {Object.entries(subscription.capabilities).map(([key, enabled]) => (
            <li key={key}>
              <span>{key.replaceAll("_", " ")}</span>
              <Badge tone={enabled ? "success" : "neutral"}>
                {enabled ? "enabled" : "unavailable"}
              </Badge>
            </li>
          ))}
        </ul>
      </Card>

      <section>
        <h2>Locations</h2>
        <DataTableShell
          caption="locations"
          empty={initial.locations.length === 0}
        >
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Code</th>
                <th>Status</th>
                <th>Timezone</th>
              </tr>
            </thead>
            <tbody>
              {initial.locations.map((location) => (
                <tr key={location.id}>
                  <td>{location.name}</td>
                  <td>{location.code}</td>
                  <td>
                    <Badge tone={tone(location.status)}>
                      {location.status}
                    </Badge>
                  </td>
                  <td>{location.timezone}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </DataTableShell>
      </section>
      <section>
        <h2>Members</h2>
        <DataTableShell caption="members" empty={initial.members.length === 0}>
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {initial.members.map((member) => (
                <tr key={member.id}>
                  <td>{member.name}</td>
                  <td>{member.email}</td>
                  <td>{member.role.replaceAll("_", " ")}</td>
                  <td>
                    <Badge tone={tone(member.status)}>{member.status}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </DataTableShell>
      </section>

      <Card>
        <h2>Initial owner invitation</h2>
        <form className="platform-inline-form" onSubmit={invite}>
          <Input
            label="Owner email"
            name="email"
            type="email"
            required
            maxLength={255}
          />
          <Button type="submit" disabled={pendingAction !== null}>
            {pendingAction === "invite-owner"
              ? "Sending…"
              : "Invite initial owner"}
          </Button>
        </form>
        <DataTableShell caption="invitations" empty={invitations.length === 0}>
          <table>
            <thead>
              <tr>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Expires</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {invitations.map((invitation) => (
                <tr key={invitation.id}>
                  <td>{invitation.intended_email}</td>
                  <td>organization owner</td>
                  <td>
                    <Badge tone={tone(invitation.status)}>
                      {invitation.status}
                    </Badge>
                  </td>
                  <td>
                    {new Date(invitation.expires_at).toLocaleString("en-US", {
                      timeZone: "UTC",
                    })}{" "}
                    UTC
                  </td>
                  <td>
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={
                        pendingAction !== null ||
                        invitation.status === "accepted"
                      }
                      onClick={() => resend(invitation)}
                    >
                      {pendingAction === `resend-${invitation.id}`
                        ? "Resending…"
                        : "Resend"}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </DataTableShell>
      </Card>

      <Card>
        <h2>Recent audit activity</h2>
        {initial.audit_activity.length === 0 ? (
          <p>No audit activity recorded.</p>
        ) : (
          <ul className="platform-audit-list">
            {initial.audit_activity.map((audit) => (
              <li key={audit.id}>
                <strong>{audit.action.replaceAll("_", " ")}</strong>
                <span>
                  {audit.actor_label ?? "System"} ·{" "}
                  {new Date(audit.occurred_at).toLocaleString("en-US", {
                    timeZone: "UTC",
                  })}{" "}
                  UTC
                </span>
                {typeof audit.metadata.reason === "string" && (
                  <small>Reason: {audit.metadata.reason}</small>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <ConfirmationDialog
        open={confirm !== null}
        onClose={() => !pendingAction && setConfirm(null)}
        onConfirm={() => {
          if (!confirm) return;
          if (confirm.kind === "organization")
            void updateOrganization(confirm.status, confirm.reason);
          else
            void updateSubscription(confirm.action, { reason: confirm.reason });
        }}
        title="Confirm high-risk action"
        confirmLabel="Confirm action"
        pending={pendingAction !== null}
      >
        <p>
          This change affects customer access immediately after the server
          confirms it. Customer data will be preserved.
        </p>
        {confirm && (
          <p>
            <strong>Reason:</strong> {confirm.reason}
          </p>
        )}
      </ConfirmationDialog>
    </div>
  );
}
