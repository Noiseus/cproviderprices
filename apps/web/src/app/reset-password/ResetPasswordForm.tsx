"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { ApiClientError, apiRequest } from "@/lib/api";
import { FormMessage } from "@/components/FormMessage";

export function ResetPasswordForm({
  token,
  email,
}: {
  token?: string;
  email?: string;
}) {
  const [message, setMessage] = useState<string>();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);
  if (!token || !email)
    return (
      <FormMessage
        error
        message="This password reset link is invalid or incomplete."
      />
    );

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(undefined);
    const data = new FormData(event.currentTarget);
    try {
      const response = await apiRequest<{ message: string }>(
        "/api/v1/auth/reset-password",
        {
          method: "POST",
          body: JSON.stringify({
            token,
            email,
            password: data.get("password"),
            password_confirmation: data.get("password_confirmation"),
          }),
        },
      );
      setMessage(response.message);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? caught.message
          : "Unable to reset the password.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={submit}>
      <label htmlFor="email">Email address</label>
      <input id="email" value={email} readOnly />
      <label htmlFor="password">New password</label>
      <input
        id="password"
        name="password"
        type="password"
        autoComplete="new-password"
        required
        minLength={8}
      />
      <label htmlFor="password_confirmation">Confirm new password</label>
      <input
        id="password_confirmation"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        minLength={8}
      />
      <FormMessage message={message} />
      <FormMessage message={error} error />
      <button type="submit" disabled={loading}>
        {loading ? "Resetting…" : "Reset password"}
      </button>
      {message ? (
        <p>
          <Link href="/login">Continue to sign in</Link>
        </p>
      ) : null}
    </form>
  );
}
