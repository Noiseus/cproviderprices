"use client";

import { Button, ErrorState } from "@/components/ui";

export default function WorkspaceError({
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <ErrorState
      title="Unable to load this workspace"
      description="An unexpected error occurred. Your organization context has been preserved."
      action={<Button onClick={reset}>Try again</Button>}
    />
  );
}
