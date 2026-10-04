import { Badge } from "@/components/ui";
import type { MatchConfidence } from "@/lib/platform-api";

export function MatchingConfidenceBadge({
  confidence,
}: {
  confidence: MatchConfidence;
}) {
  return (
    <Badge
      tone={
        confidence === "A"
          ? "success"
          : confidence === "B" || confidence === "C"
            ? "warning"
            : "danger"
      }
    >
      Match confidence {confidence}
    </Badge>
  );
}
