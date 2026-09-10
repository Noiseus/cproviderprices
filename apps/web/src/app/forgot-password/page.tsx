import { AuthShell } from "@/components/AuthShell";
import { ForgotPasswordForm } from "./ForgotPasswordForm";

export default function ForgotPasswordPage() {
  return (
    <AuthShell title="Reset your password">
      <p>Enter your account email to request a reset link.</p>
      <ForgotPasswordForm />
    </AuthShell>
  );
}
