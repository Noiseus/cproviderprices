"use client";

import { FormEvent, useState } from "react";
import { ApiClientError, apiRequest } from "@/lib/api";
import { FormMessage } from "@/components/FormMessage";

export function ForgotPasswordForm() {
  const [message, setMessage] = useState<string>();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(undefined);
    setMessage(undefined);
    const data = new FormData(event.currentTarget);
    try {
      const response = await apiRequest<{ message: string }>(
        "/api/v1/auth/forgot-password",
        {
          method: "POST",
          body: JSON.stringify({ email: data.get("email") }),
        },
      );
      setMessage(response.message);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? caught.message
          : "Unable to request a reset.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={submit}>
      <label htmlFor="email">Email address</label>
      <input
        id="email"
        name="email"
        type="email"
        autoComplete="email"
        required
      />
      <FormMessage message={message} />
      <FormMessage message={error} error />
      <button type="submit" disabled={loading}>
        {loading ? "Sending…" : "Send reset instructions"}
      </button>
    </form>
  );
}
