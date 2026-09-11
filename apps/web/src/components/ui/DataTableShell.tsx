import type { ReactNode } from "react";
import { EmptyState, ErrorState, Skeleton } from "./feedback";
import { Pagination } from "./layout";

export function DataTableShell({
  children,
  loading = false,
  empty = false,
  error,
  caption,
  pagination,
}: {
  children?: ReactNode;
  loading?: boolean;
  empty?: boolean;
  error?: string;
  caption: string;
  pagination?: { page: number; totalPages: number };
}) {
  if (loading) return <Skeleton lines={5} label={`Loading ${caption}`} />;
  if (error)
    return (
      <ErrorState title={`Unable to load ${caption}`} description={error} />
    );
  if (empty)
    return (
      <EmptyState
        title={`No ${caption}`}
        description="Items will appear here when they are available."
      />
    );
  return (
    <div className="ui-table-shell">
      <div className="ui-table-scroll">{children}</div>
      {pagination && <Pagination {...pagination} />}
    </div>
  );
}
