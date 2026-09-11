import { Badge, Card, EmptyState, PageHeader, StatCard } from "../ui";

export function RoutePlaceholder({
  title,
  description,
  audience = "Workspace",
}: {
  title: string;
  description: string;
  audience?: string;
}) {
  return (
    <div className="route-placeholder">
      <PageHeader
        eyebrow={audience}
        title={title}
        description={description}
        actions={<Badge tone="accent">Route shell</Badge>}
      />
      <div className="route-placeholder__grid">
        <StatCard label="Status" value="Ready" detail="Foundation available" />
        <Card>
          <h2>Context preserved</h2>
          <p>
            Organization and location context remain visible while this module
            is developed.
          </p>
        </Card>
      </div>
      <EmptyState
        title={`${title} is ready for its feature prompt`}
        description="No business data or workflow has been implemented in this route shell."
      />
    </div>
  );
}
