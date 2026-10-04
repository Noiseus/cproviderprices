import { ErrorState } from "@/components/ui";
import { MatchingReviewView } from "@/components/platform/MatchingReviewView";
import {
  matchingReturnHref,
  platformServerRequest,
  type PlatformMatchCandidateDetail,
} from "@/lib/platform-api";

export default async function PlatformMatchingDetailPage({
  params,
  searchParams,
}: {
  params: Promise<{ candidateId: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { candidateId } = await params;
  const values = await searchParams;
  try {
    const result = await platformServerRequest<{
      match_candidate: PlatformMatchCandidateDetail;
    }>(
      `/api/v1/platform/matching/candidates/${encodeURIComponent(candidateId)}`,
    );
    return (
      <MatchingReviewView
        initial={result.match_candidate}
        returnHref={matchingReturnHref(values.return)}
      />
    );
  } catch (error) {
    return (
      <ErrorState
        title="Unable to load matching candidate"
        description={
          error instanceof Error
            ? error.message
            : "The matching candidate is unavailable."
        }
      />
    );
  }
}
