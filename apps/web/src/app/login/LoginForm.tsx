"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useState } from "react";
import { ApiClientError, apiRequest } from "@/lib/api";
import { FormMessage } from "@/components/FormMessage";
import { useTranslation } from "@/components/i18n/I18nProvider";

export function LoginForm({
  intendedDestination,
}: {
  intendedDestination?: string;
}) {
  const router = useRouter();
  const { t, localizeError } = useTranslation();
  const [error, setError] = useState<string>();
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(undefined);
    const data = new FormData(event.currentTarget);

    try {
      const response = await apiRequest<{ destination: string }>(
        "/api/v1/auth/login",
        {
          method: "POST",
          body: JSON.stringify({
            email: data.get("email"),
            password: data.get("password"),
            intended_destination: intendedDestination,
          }),
        },
      );
      router.push(response.destination);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? localizeError(caught.code)
          : localizeError("network_error"),
      );
      setLoading(false);
    }
  }

  return (
    <form onSubmit={submit}>
      <label htmlFor="email">{t("auth.email")}</label>
      <input
        id="email"
        name="email"
        type="email"
        autoComplete="email"
        required
      />
      <label htmlFor="password">{t("auth.password")}</label>
      <input
        id="password"
        name="password"
        type="password"
        autoComplete="current-password"
        required
      />
      <FormMessage message={error} error />
      <button type="submit" disabled={loading}>
        {loading ? t("common.saving") : t("auth.signIn")}
      </button>
      <p>
        <Link href="/forgot-password">{t("auth.forgotPassword")}</Link>
      </p>
    </form>
  );
}
