import { AuthShell } from "@/components/AuthShell";
import { ResetPasswordForm } from "./ResetPasswordForm";

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const params = await searchParams;
  return (
    <AuthShell title="Choose a new password">
      <ResetPasswordForm token={params.token} email={params.email} />
    </AuthShell>
  );
}
