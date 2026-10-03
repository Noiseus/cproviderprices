"use client";

import { createContext, useContext, type ReactNode } from "react";
import type { OrganizationSession, SessionPayload } from "@/lib/server-session";
import type { SubscriptionStatusPayload } from "@/lib/server-subscription";
import type { Location } from "@/lib/customer-api";

export type WorkspaceContextValue = {
  user: SessionPayload["session"]["user"];
  organization: OrganizationSession;
  organizations: OrganizationSession[];
  subscription: SubscriptionStatusPayload | null;
  locations: Location[];
  locationId: string;
};

const WorkspaceContext = createContext<WorkspaceContextValue | null>(null);

export function WorkspaceProvider({
  value,
  children,
}: {
  value: WorkspaceContextValue;
  children: ReactNode;
}) {
  return (
    <WorkspaceContext.Provider value={value}>
      {children}
    </WorkspaceContext.Provider>
  );
}

export function useWorkspace(): WorkspaceContextValue {
  const value = useContext(WorkspaceContext);
  if (!value)
    throw new Error(
      "useWorkspace must be used within the authenticated workspace.",
    );
  return value;
}
