import { redirect } from "next/navigation";
import type { ReactNode } from "react";
import { PlatformShell } from "@/components/platform/PlatformShell";
import { ShellFailure } from "@/components/app-shell/ShellStates";
import { currentSessionResult } from "@/lib/server-session";

export default async function PlatformLayout({
  children,
}: {
  children: ReactNode;
}) {
  const result = await currentSessionResult();
  if (result.kind === "unauthenticated") redirect("/login?next=%2Fplatform");
  if (result.kind !== "authenticated")
    return <ShellFailure kind={result.kind} />;
  if (result.data.session.user.platform_role !== "platform_admin")
    return <ShellFailure kind="forbidden" />;

  return (
    <PlatformShell
      user={result.data.session.user}
      organizations={result.data.session.organizations}
    >
      {children}
    </PlatformShell>
  );
}
