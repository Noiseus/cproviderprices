import { SupplierAccountManager } from "@/components/workspace/SupplierAccountManager";
import { tenantServerRequest, type Location } from "@/lib/customer-api";
import { currentSession } from "@/lib/server-session";
export default async function SuppliersPage({
  params,
}: {
  params: Promise<{ organizationSlug: string }>;
}) {
  const { organizationSlug } = await params;
  const session = await currentSession();
  const organization = session?.session.organizations.find(
    (item) => item.slug === organizationSlug,
  );
  const locations = organization
    ? (
        await tenantServerRequest<{ restaurant_locations: Location[] }>(
          organization.id,
          "restaurant-locations",
        )
      ).restaurant_locations
    : [];
  return <SupplierAccountManager locations={locations} />;
}
