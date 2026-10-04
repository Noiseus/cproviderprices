import { ComparisonWorkspace } from "@/components/comparison/ComparisonWorkspace";

export default async function ComparisonPage({
  params,
}: {
  params: Promise<{ canonicalProductId: string }>;
}) {
  const { canonicalProductId } = await params;
  return <ComparisonWorkspace canonicalProductId={canonicalProductId} />;
}
