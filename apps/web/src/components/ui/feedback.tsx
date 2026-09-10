"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { cn } from "@/lib/cn";
import { Button } from "./controls";

type DialogProps = {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  className?: string;
};

export function Dialog({
  open,
  onClose,
  title,
  children,
  className,
}: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const returnFocus = useRef<HTMLElement | null>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) {
      returnFocus.current = document.activeElement as HTMLElement | null;
      if (typeof dialog.showModal === "function") dialog.showModal();
      else dialog.setAttribute("open", "");
    } else if (!open && dialog.open) {
      if (typeof dialog.close === "function") dialog.close();
      else dialog.removeAttribute("open");
      returnFocus.current?.focus();
    }
  }, [open]);

  return (
    <dialog
      ref={ref}
      className={cn("ui-dialog", className)}
      aria-labelledby={titleId}
      onCancel={onClose}
      onClose={() => returnFocus.current?.focus()}
    >
      <div className="ui-dialog__header">
        <h2 id={titleId}>{title}</h2>
        <Button
          variant="quiet"
          size="sm"
          onClick={onClose}
          aria-label={`Close ${title}`}
        >
          Close
        </Button>
      </div>
      {children}
    </dialog>
  );
}

export function Drawer(props: DialogProps & { side?: "left" | "right" }) {
  return (
    <Dialog
      {...props}
      className={cn(
        "ui-drawer",
        `ui-drawer--${props.side ?? "left"}`,
        props.className,
      )}
    />
  );
}

export function ConfirmationDialog({
  open,
  onClose,
  onConfirm,
  title,
  children,
  confirmLabel = "Confirm",
}: DialogProps & { onConfirm: () => void; confirmLabel?: string }) {
  return (
    <Dialog open={open} onClose={onClose} title={title}>
      {children}
      <div className="ui-dialog__actions">
        <Button variant="secondary" onClick={onClose}>
          Cancel
        </Button>
        <Button variant="danger" onClick={onConfirm}>
          {confirmLabel}
        </Button>
      </div>
    </Dialog>
  );
}

export function Dropdown({
  label,
  children,
}: {
  label: ReactNode;
  children: ReactNode;
}) {
  return (
    <details
      className="ui-dropdown"
      onKeyDown={(event) => {
        if (event.key === "Escape")
          (event.currentTarget as HTMLDetailsElement).open = false;
      }}
    >
      <summary>{label}</summary>
      <div className="ui-dropdown__menu">{children}</div>
    </details>
  );
}

export function Tooltip({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  const id = useId();
  return (
    <span className="ui-tooltip" aria-describedby={id}>
      {children}
      <span role="tooltip" id={id}>
        {label}
      </span>
    </span>
  );
}

export function Toast({
  title,
  children,
  tone = "neutral",
}: {
  title: string;
  children?: ReactNode;
  tone?: "neutral" | "success" | "danger";
}) {
  return (
    <div
      className={cn("ui-toast", `ui-toast--${tone}`)}
      role={tone === "danger" ? "alert" : "status"}
    >
      <strong>{title}</strong>
      {children}
    </div>
  );
}

export function Skeleton({
  lines = 3,
  label = "Loading",
}: {
  lines?: number;
  label?: string;
}) {
  return (
    <div className="ui-skeleton" role="status" aria-label={label}>
      {Array.from({ length: lines }, (_, index) => (
        <span key={index} />
      ))}
    </div>
  );
}

type StateProps = { title: string; description: string; action?: ReactNode };

function State({
  title,
  description,
  action,
  tone,
}: StateProps & { tone: string }) {
  return (
    <section className={cn("ui-state", `ui-state--${tone}`)}>
      <h2>{title}</h2>
      <p>{description}</p>
      {action}
    </section>
  );
}

export const EmptyState = (props: StateProps) => (
  <State {...props} tone="empty" />
);
export const ErrorState = (props: StateProps) => (
  <State {...props} tone="error" />
);
export const PermissionState = (props: StateProps) => (
  <State {...props} tone="permission" />
);

export function PartialDataWarning({ children }: { children: ReactNode }) {
  return (
    <div className="ui-partial-warning" role="status">
      <strong>Partial data</strong>
      <span>{children}</span>
    </div>
  );
}
