"use client";

import { Button, ErrorState } from "@/components/ui";

export default function PlatformError({ reset }: { reset: () => void }) {
  return (
    <ErrorState
      title="Platform workspace unavailable"
      description="The platform service could not complete this request."
      action={<Button onClick={reset}>Try again</Button>}
    />
  );
}
