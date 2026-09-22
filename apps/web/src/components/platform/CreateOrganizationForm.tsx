"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { apiRequest, ApiClientError } from "@/lib/api";
import { Button, Card, Input, Toast } from "../ui";

export function CreateOrganizationForm() {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const values = new FormData(event.currentTarget);
    setPending(true);
    setError(null);
    try {
      const response = await apiRequest<{ organization: { id: string } }>(
        "/api/v1/platform/organizations",
        {
          method: "POST",
          body: JSON.stringify({
            name: values.get("name"),
            slug: values.get("slug"),
          }),
        },
      );
      router.push(`/platform/organizations/${response.organization.id}`);
    } catch (caught) {
      setError(
        caught instanceof ApiClientError
          ? caught.message
          : "The organization could not be created.",
      );
      setPending(false);
    }
  }

  return (
    <Card className="platform-form-card">
      <form className="platform-form" onSubmit={submit}>
        <Input
          label="Organization name"
          name="name"
          required
          maxLength={255}
          autoComplete="organization"
        />
        <Input
          label="Organization slug"
          name="slug"
          required
          minLength={2}
          maxLength={120}
          pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
          hint="Lowercase letters, numbers, and single hyphens."
        />
        {error && (
          <Toast title="Creation failed" tone="danger">
            {error}
          </Toast>
        )}
        <Button type="submit" disabled={pending}>
          {pending ? "Creating…" : "Create organization"}
        </Button>
      </form>
    </Card>
  );
}
