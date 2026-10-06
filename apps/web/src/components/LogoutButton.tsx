"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { apiRequest } from "@/lib/api";
import { Button } from "./ui";
import { useTranslation } from "./i18n/I18nProvider";

export function LogoutButton() {
  const router = useRouter();
  const { t } = useTranslation();
  const [loading, setLoading] = useState(false);
  return (
    <Button
      type="button"
      disabled={loading}
      onClick={async () => {
        setLoading(true);
        try {
          await apiRequest("/api/v1/auth/session", { method: "DELETE" });
        } finally {
          router.push("/login");
        }
      }}
    >
      {loading ? t("common.saving") : t("auth.signOut")}
    </Button>
  );
}
