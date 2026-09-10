"use client";

import {
  forwardRef,
  useId,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
} from "react";
import { cn } from "@/lib/cn";

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "secondary" | "quiet" | "danger";
  size?: "sm" | "md";
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    { className, variant = "primary", size = "md", type = "button", ...props },
    ref,
  ) => (
    <button
      ref={ref}
      type={type}
      className={cn(
        "ui-button",
        `ui-button--${variant}`,
        `ui-button--${size}`,
        className,
      )}
      {...props}
    />
  ),
);
Button.displayName = "Button";

export function IconButton({
  label,
  ...props
}: ButtonProps & { label: string }) {
  return (
    <Button
      {...props}
      className={cn("ui-icon-button", props.className)}
      aria-label={label}
    />
  );
}

type FieldProps = { label: string; hint?: string; error?: string };

export const Input = forwardRef<
  HTMLInputElement,
  InputHTMLAttributes<HTMLInputElement> & FieldProps
>(({ label, hint, error, id, className, ...props }, ref) => {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const descriptionId = hint || error ? `${inputId}-description` : undefined;
  return (
    <label className="ui-field" htmlFor={inputId}>
      <span>{label}</span>
      <input
        {...props}
        ref={ref}
        id={inputId}
        className={cn("ui-input", className)}
        aria-invalid={error ? true : undefined}
        aria-describedby={descriptionId}
      />
      {(error || hint) && (
        <small
          id={descriptionId}
          className={error ? "ui-field__error" : undefined}
        >
          {error ?? hint}
        </small>
      )}
    </label>
  );
});
Input.displayName = "Input";

export const SearchInput = forwardRef<
  HTMLInputElement,
  InputHTMLAttributes<HTMLInputElement> &
    Omit<FieldProps, "label"> & { label?: string }
>(({ label = "Search", ...props }, ref) => (
  <Input {...props} ref={ref} type="search" label={label} />
));
SearchInput.displayName = "SearchInput";

export function Select({
  label,
  hint,
  error,
  id,
  className,
  children,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & FieldProps) {
  const generatedId = useId();
  const selectId = id ?? generatedId;
  const descriptionId = hint || error ? `${selectId}-description` : undefined;
  return (
    <label className="ui-field" htmlFor={selectId}>
      <span>{label}</span>
      <select
        {...props}
        id={selectId}
        className={cn("ui-select", className)}
        aria-invalid={error ? true : undefined}
        aria-describedby={descriptionId}
      >
        {children}
      </select>
      {(error || hint) && <small id={descriptionId}>{error ?? hint}</small>}
    </label>
  );
}

export function Checkbox({
  label,
  description,
  id,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  description?: string;
}) {
  const generatedId = useId();
  const checkboxId = id ?? generatedId;
  return (
    <label
      className={cn("ui-checkbox", props.disabled && "is-disabled")}
      htmlFor={checkboxId}
    >
      <input {...props} id={checkboxId} type="checkbox" />
      <span>
        <strong>{label}</strong>
        {description && <small>{description}</small>}
      </span>
    </label>
  );
}

export type TabItem = { value: string; label: string; panel: ReactNode };

export function Tabs({
  items,
  defaultValue,
  label,
}: {
  items: TabItem[];
  defaultValue?: string;
  label: string;
}) {
  const [selected, setSelected] = useState(defaultValue ?? items[0]?.value);
  const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);
  const selectedIndex = Math.max(
    0,
    items.findIndex((item) => item.value === selected),
  );

  function selectAt(index: number) {
    const next = items[index];
    if (!next) return;
    setSelected(next.value);
    tabRefs.current[index]?.focus();
  }

  return (
    <div className="ui-tabs">
      <div role="tablist" aria-label={label}>
        {items.map((item, index) => (
          <button
            key={item.value}
            ref={(node) => {
              tabRefs.current[index] = node;
            }}
            type="button"
            role="tab"
            id={`tab-${item.value}`}
            aria-controls={`panel-${item.value}`}
            aria-selected={selected === item.value}
            tabIndex={selected === item.value ? 0 : -1}
            onClick={() => setSelected(item.value)}
            onKeyDown={(event) => {
              if (event.key === "ArrowRight")
                selectAt((selectedIndex + 1) % items.length);
              else if (event.key === "ArrowLeft")
                selectAt((selectedIndex - 1 + items.length) % items.length);
              else if (event.key === "Home") selectAt(0);
              else if (event.key === "End") selectAt(items.length - 1);
              else return;
              event.preventDefault();
            }}
          >
            {item.label}
          </button>
        ))}
      </div>
      {items.map((item) =>
        item.value === selected ? (
          <div
            key={item.value}
            role="tabpanel"
            id={`panel-${item.value}`}
            aria-labelledby={`tab-${item.value}`}
          >
            {item.panel}
          </div>
        ) : null,
      )}
    </div>
  );
}
