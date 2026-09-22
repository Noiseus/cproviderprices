import { ErrorState } from "@/components/ui";
import { PlatformOrganizationView } from "@/components/platform/PlatformOrganizationView";
import {
  platformReturnHref,
  platformServerRequest,
  type PlatformOrganizationDetail,
} from "@/lib/platform-api";

export default async function PlatformOrganizationPage({
  params,
  searchParams,
}: {
  params: Promise<{ organizationId: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { organizationId } = await params;
  const query = await searchParams;
  let detail: PlatformOrganizationDetail;
  try {
    const response = await platformServerRequest<{
      platform_organization: PlatformOrganizationDetail;
    }>(`/api/v1/platform/organizations/${encodeURIComponent(organizationId)}`);
    detail = response.platform_organization;
  } catch (error) {
    return (
      <ErrorState
        title="Unable to load organization"
        description={
          error instanceof Error
            ? error.message
            : "The organization is unavailable."
        }
      />
    );
  }

  return (
    <PlatformOrganizationView
      initial={detail}
      returnHref={platformReturnHref(query.return)}
    />
  );
}
