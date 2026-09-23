import type { OrganizationSession } from "./server-session";

export type NavigationItem = {
  label: string;
  segment: string;
  icon: string;
  available: boolean;
  unavailableReason?: string;
  prefetch: boolean;
};

type Definition = Omit<NavigationItem, "available" | "unavailableReason"> & {
  roles?: OrganizationSession["role"][];
  entitlement?: string;
};

const definitions: Definition[] = [
  { label: "Dashboard", segment: "dashboard", icon: "DB", prefetch: true },
  { label: "Search", segment: "search", icon: "SE", prefetch: true },
  { label: "Lists", segment: "lists", icon: "LI", prefetch: false },
  {
    label: "Price history",
    segment: "price-history",
    icon: "PH",
    entitlement: "price_history_enabled",
    prefetch: false,
  },
  {
    label: "Suppliers",
    segment: "suppliers",
    icon: "SU",
    prefetch: false,
  },
  {
    label: "Imports",
    segment: "imports",
    icon: "IM",
    roles: ["organization_owner", "buyer"],
    entitlement: "manual_imports_enabled",
    prefetch: false,
  },
  {
    label: "Team",
    segment: "team",
    icon: "TM",
    prefetch: false,
  },
  {
    label: "Locations",
    segment: "locations",
    icon: "LO",
    prefetch: false,
  },
  {
    label: "Subscription",
    segment: "subscription",
    icon: "SB",
    prefetch: false,
  },
  {
    label: "Settings",
    segment: "settings",
    icon: "ST",
    roles: ["organization_owner"],
    prefetch: false,
  },
];

export function buildNavigation(
  role: OrganizationSession["role"],
  capabilities: Record<string, boolean>,
): NavigationItem[] {
  return definitions
    .filter((item) => !item.roles || item.roles.includes(role))
    .map((definition) => ({
      label: definition.label,
      segment: definition.segment,
      icon: definition.icon,
      prefetch: definition.prefetch,
      available: definition.entitlement
        ? capabilities[definition.entitlement] === true
        : true,
      unavailableReason:
        definition.entitlement && capabilities[definition.entitlement] !== true
          ? "Unavailable on the current plan"
          : undefined,
    }));
}

export function titleForSegment(segment: string): string {
  return (
    definitions.find((item) => item.segment === segment)?.label ?? "Workspace"
  );
}
