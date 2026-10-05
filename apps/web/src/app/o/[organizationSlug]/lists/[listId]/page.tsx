import { SavedListDetailWorkspace } from "@/components/lists/SavedListDetailWorkspace";

export default async function SavedListPage({
  params,
}: {
  params: Promise<{ listId: string }>;
}) {
  const { listId } = await params;

  return <SavedListDetailWorkspace listId={listId} />;
}
