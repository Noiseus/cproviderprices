"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { apiRequest } from "@/lib/api";
import type { OrganizationSession } from "@/lib/server-session";

export function OrganizationSwitcher({
  current,
  organizations,
}: {
  current: OrganizationSession;
  organizations: OrganizationSession[];
}) {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  if (organizations.length < 2) return null;

  return (
    <label className="switcher">
      Organization
      <select
        value={current.id}
        disabled={loading}
        onChange={async (event) => {
          setLoading(true);
          const selected = organizations.find(
            (organization) => organization.id === event.target.value,
          );
          if (!selected) {
            setLoading(false);
            return;
          }
          try {
            const response = await apiRequest<{ destination: string }>(
              `/api/v1/organizations/${selected.id}/switch`,
              { method: "POST", body: JSON.stringify({}) },
            );
            router.push(response.destination);
            router.refresh();
          } finally {
            setLoading(false);
          }
        }}
      >
        {organizations.map((organization) => (
          <option value={organization.id} key={organization.id}>
            {organization.name}
          </option>
        ))}
      </select>
    </label>
  );
}
