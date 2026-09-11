import { Skeleton } from "@/components/ui";

export default function WorkspaceLoading() {
  return (
    <section aria-label="Loading route">
      <Skeleton lines={5} label="Loading workspace route" />
    </section>
  );
}
