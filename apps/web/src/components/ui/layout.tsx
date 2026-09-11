import type { HTMLAttributes, ReactNode } from "react";
import { cn } from "@/lib/cn";
import { Button } from "./controls";

export function ResponsiveContainer({
  className,
  ...props
}: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("ui-container", className)} {...props} />;
}

export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
}: {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    <header className="ui-page-header">
      <div>
        {eyebrow && <p className="eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      {actions && <div className="ui-page-header__actions">{actions}</div>}
    </header>
  );
}

export function FilterBar({ children }: { children: ReactNode }) {
  return (
    <div className="ui-filter-bar" role="search">
      {children}
    </div>
  );
}

export function Pagination({
  page,
  totalPages,
  onPrevious,
  onNext,
}: {
  page: number;
  totalPages: number;
  onPrevious?: () => void;
  onNext?: () => void;
}) {
  return (
    <nav className="ui-pagination" aria-label="Pagination">
      <Button variant="secondary" onClick={onPrevious} disabled={page <= 1}>
        Previous
      </Button>
      <span>
        Page {page} of {totalPages}
      </span>
      <Button
        variant="secondary"
        onClick={onNext}
        disabled={page >= totalPages}
      >
        Next
      </Button>
    </nav>
  );
}
