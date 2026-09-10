import { AuthShell } from "@/components/AuthShell";
import { VerifyEmailForm } from "./VerifyEmailForm";

export default async function VerifyEmailPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const id = typeof params.id === "string" ? params.id : "";
  const hash = typeof params.hash === "string" ? params.hash : "";
  const query =
    id && hash
      ? `${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${new URLSearchParams(
          {
            expires: typeof params.expires === "string" ? params.expires : "",
            signature:
              typeof params.signature === "string" ? params.signature : "",
          },
        )}`
      : "";
  return (
    <AuthShell title="Verify your email">
      <VerifyEmailForm query={query} />
    </AuthShell>
  );
}
