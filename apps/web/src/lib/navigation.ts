import type { OrganizationSession } from "./server-session";
import { translate } from "@cproviderprices/i18n";

export type NavigationItem = {
  label: string;
  segment: string;
  icon: string;
  available: boolean;
  unavailableReason?: string;
  prefetch: boolean;
};

type Translate = (key: string) => string;

type Definition = Omit<
  NavigationItem,
  "label" | "available" | "unavailableReason"
> & {
  roles?: OrganizationSession["role"][];
  entitlement?: string;
};

const definitions: (Definition & { translationKey: string })[] = [
  {
    translationKey: "nav.dashboard",
    segment: "dashboard",
    icon: "DB",
    prefetch: true,
  },
  {
    translationKey: "nav.search",
    segment: "search",
    icon: "SE",
    prefetch: true,
  },
  {
    translationKey: "nav.lists",
    segment: "lists",
    icon: "LI",
    prefetch: false,
  },
  {
    translationKey: "nav.priceHistory",
    segment: "price-history",
    icon: "PH",
    entitlement: "price_history_enabled",
    prefetch: false,
  },
  {
    translationKey: "nav.suppliers",
    segment: "suppliers",
    icon: "SU",
    prefetch: false,
  },
  {
    translationKey: "nav.imports",
    segment: "imports",
    icon: "IM",
    // Historical imports remain readable by viewers and after feature disablement.
    prefetch: false,
  },
  {
    translationKey: "nav.team",
    segment: "team",
    icon: "TM",
    prefetch: false,
  },
  {
    translationKey: "nav.locations",
    segment: "locations",
    icon: "LO",
    prefetch: false,
  },
  {
    translationKey: "nav.subscription",
    segment: "subscription",
    icon: "SB",
    prefetch: false,
  },
  {
    translationKey: "nav.settings",
    segment: "settings",
    icon: "ST",
    prefetch: false,
  },
];

export function buildNavigation(
  role: OrganizationSession["role"],
  capabilities: Record<string, boolean>,
  t: Translate = (key) => translate("en", key),
): NavigationItem[] {
  return definitions
    .filter((item) => !item.roles || item.roles.includes(role))
    .map((definition) => ({
      label: t(definition.translationKey),
      segment: definition.segment,
      icon: definition.icon,
      prefetch: definition.prefetch,
      available: definition.entitlement
        ? capabilities[definition.entitlement] === true
        : true,
      unavailableReason:
        definition.entitlement && capabilities[definition.entitlement] !== true
          ? t("nav.unavailable")
          : undefined,
    }));
}

export function titleForSegment(
  segment: string,
  t: Translate = (key) => translate("en", key),
): string {
  return t(
    definitions.find((item) => item.segment === segment)?.translationKey ??
      "nav.workspace",
  );
}
