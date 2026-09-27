import { ImportCenter } from "@/components/imports/ImportCenter";

export default async function ImportDetailPage({ params }: { params: Promise<{ importId: string }> }) {
  const { importId } = await params;
  return <ImportCenter importId={importId} />;
}
