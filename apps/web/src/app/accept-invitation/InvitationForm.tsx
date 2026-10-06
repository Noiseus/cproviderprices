"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiClientError, apiRequest } from "@/lib/api";
import { FormMessage } from "@/components/FormMessage";

type Invitation = {
  intended_email: string;
  intended_role: string;
  organization: { name: string; slug: string };
};

export function InvitationForm({
  token,
  invitation,
}: {
  token: string;
  invitation: Invitation;
}) {
  const router = useRouter();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(undefined);
    const data = new FormData(event.currentTarget);
    try {
      const response = await apiRequest<{ destination: string }>(
        "/api/v1/auth/invitations/accept",
        {
          method: "POST",
          body: JSON.stringify({
            token,
            email: invitation.intended_email,
            name: data.get("name"),
            password: data.get("password"),
            password_confirmation: data.get("password_confirmation"),
          }),
        },
      );
      router.push(response.destination);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? caught.message
          : "Unable to accept this invitation.",
      );
      setLoading(false);
    }
  }

  return (
    <>
      <p>
        You were invited to <strong>{invitation.organization.name}</strong> as{" "}
        {invitation.intended_role.replaceAll("_", " ")}.
      </p>
      <form onSubmit={submit}>
        <label htmlFor="email">Invited email</label>
        <input id="email" value={invitation.intended_email} readOnly />
        <label htmlFor="name">Your name</label>
        <input id="name" name="name" autoComplete="name" required />
        <label htmlFor="password">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
          minLength={8}
        />
        <label htmlFor="password_confirmation">Confirm password</label>
        <input
          id="password_confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
          minLength={8}
        />
        <FormMessage message={error} error />
        <button type="submit" disabled={loading}>
          {loading ? "Activating…" : "Accept invitation"}
        </button>
      </form>
    </>
  );
}
