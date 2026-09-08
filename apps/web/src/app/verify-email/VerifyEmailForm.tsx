"use client";

import Link from "next/link";
import { useState } from "react";
import { ApiClientError, apiRequest } from "@/lib/api";
import { FormMessage } from "@/components/FormMessage";

export function VerifyEmailForm({ query }: { query: string }) {
  const [message, setMessage] = useState<string>();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);

  async function verify() {
    setLoading(true);
    setError(undefined);
    try {
      const response = await apiRequest<{ message: string }>(
        `/api/v1/auth/verify-email/${query}`,
      );
      setMessage(response.message);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? caught.message
          : "Unable to verify this email.",
      );
    } finally {
      setLoading(false);
    }
  }

  if (!query)
    return (
      <FormMessage
        message="This verification link is invalid or incomplete."
        error
      />
    );
  return (
    <>
      <button type="button" onClick={verify} disabled={loading}>
        {loading ? "Verifying…" : "Verify email"}
      </button>
      <FormMessage message={message} />
      <FormMessage message={error} error />
      {message ? (
        <p>
          <Link href="/">Continue</Link>
        </p>
      ) : null}
    </>
  );
}
