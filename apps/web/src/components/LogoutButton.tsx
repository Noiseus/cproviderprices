"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { apiRequest } from "@/lib/api";

export function LogoutButton() {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  return (
    <button
      type="button"
      disabled={loading}
      onClick={async () => {
        setLoading(true);
        try {
          await apiRequest("/api/v1/auth/session", { method: "DELETE" });
        } finally {
          router.push("/login");
          router.refresh();
        }
      }}
    >
      {loading ? "Signing out…" : "Sign out"}
    </button>
  );
}
