import { describe, expect, it } from "vitest";

import { selectActiveLocation, selectOrganization } from "@/lib/workspace";
import type { Session } from "@/types/api";

describe("server-authoritative workspace selection", () => {
  it("only selects an organization in the authenticated server session", () => {
    const session: Session = {
      user: {
        id: "user-1",
        name: "Buyer",
        email: "buyer@example.test",
        email_verified: true,
        platform_role: null,
        preferred_language: "en",
      },
      organizations: [
        {
          id: "org-1",
          name: "Allowed",
          slug: "allowed",
          status: "active",
          role: "buyer",
        },
      ],
    };
    expect(selectOrganization(session, "org-1")?.name).toBe("Allowed");
    expect(selectOrganization(session, "org-2")).toBeNull();
  });

  it("does not accept a location from another organization or an inactive location", () => {
    expect(
      selectActiveLocation("org-1", {
        id: "location-1",
        organization_id: "org-1",
        name: "Main",
        status: "active",
        timezone: "UTC",
      })?.id,
    ).toBe("location-1");
    expect(
      selectActiveLocation("org-1", {
        id: "location-2",
        organization_id: "org-2",
        name: "Other",
        status: "active",
        timezone: "UTC",
      }),
    ).toBeNull();
    expect(
      selectActiveLocation("org-1", {
        id: "location-3",
        organization_id: "org-1",
        name: "Inactive",
        status: "inactive",
        timezone: "UTC",
      }),
    ).toBeNull();
  });
});
