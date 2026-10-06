import type { PropsWithChildren } from "react";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

import { createMobileApiClient } from "@/lib/api-client";
import { mobileAuthStorage } from "@/lib/secure-storage";
import { selectOrganization } from "@/lib/workspace";
import type { Session } from "@/types/api";

const mobileApiClient = createMobileApiClient({
  baseUrl: process.env.EXPO_PUBLIC_API_URL ?? "",
  authStorage: mobileAuthStorage,
});

type Organization = Session["organizations"][number];

interface SelectedLocation {
  id: string;
  name: string;
  timezone: string;
}

interface MobileSessionValue {
  api: typeof mobileApiClient;
  booting: boolean;
  session: Session | null;
  activeOrganization: Organization | null;
  activeLocation: SelectedLocation | null;
  signIn(email: string, password: string): Promise<void>;
  signOut(): Promise<void>;
  chooseOrganization(organizationId: string): void;
  chooseLocation(location: SelectedLocation): void;
  updateLanguage(locale: "en" | "es"): Promise<void>;
}

const MobileSessionContext = createContext<MobileSessionValue | null>(null);

export function MobileSessionProvider({ children }: PropsWithChildren) {
  const [booting, setBooting] = useState(true);
  const [session, setSession] = useState<Session | null>(null);
  const [activeOrganization, setActiveOrganization] =
    useState<Organization | null>(null);
  const [activeLocation, setActiveLocation] = useState<SelectedLocation | null>(
    null,
  );

  useEffect(() => {
    let active = true;

    void (async () => {
      const stored = await mobileAuthStorage.read();
      if (
        stored === null ||
        new Date(stored.expiresAt).getTime() <= Date.now()
      ) {
        await mobileAuthStorage.clear();
      } else {
        try {
          const restoredSession = await mobileApiClient.session();
          if (active) {
            setSession(restoredSession);
            setActiveOrganization(restoredSession.organizations[0] ?? null);
          }
        } catch {
          await mobileAuthStorage.clear();
        }
      }

      if (active) {
        setBooting(false);
      }
    })();

    return () => {
      active = false;
    };
  }, []);

  const signIn = useCallback(
    async (email: string, password: string): Promise<void> => {
      const response = await mobileApiClient.login(
        email,
        password,
        "CProviderPrices mobile",
      );
      await mobileAuthStorage.write({
        accessToken: response.access_token,
        expiresAt: response.expires_at,
      });
      setSession(response.session);
      setActiveOrganization(response.session.organizations[0] ?? null);
      setActiveLocation(null);
    },
    [],
  );

  const signOut = useCallback(async (): Promise<void> => {
    try {
      await mobileApiClient.logout();
    } finally {
      await mobileAuthStorage.clear();
      setSession(null);
      setActiveOrganization(null);
      setActiveLocation(null);
    }
  }, []);

  const chooseOrganization = useCallback(
    (organizationId: string): void => {
      if (session === null) {
        return;
      }
      setActiveOrganization(selectOrganization(session, organizationId));
      setActiveLocation(null);
    },
    [session],
  );

  const updateLanguage = useCallback(
    async (locale: "en" | "es"): Promise<void> => {
      const nextSession = await mobileApiClient.updateLanguage(locale);
      setSession(nextSession);
    },
    [],
  );

  const value = useMemo<MobileSessionValue>(
    () => ({
      api: mobileApiClient,
      booting,
      session,
      activeOrganization,
      activeLocation,
      signIn,
      signOut,
      chooseOrganization,
      chooseLocation: setActiveLocation,
      updateLanguage,
    }),
    [
      activeLocation,
      activeOrganization,
      booting,
      chooseOrganization,
      session,
      signIn,
      signOut,
      updateLanguage,
    ],
  );

  return (
    <MobileSessionContext.Provider value={value}>
      {children}
    </MobileSessionContext.Provider>
  );
}

export function useMobileSession(): MobileSessionValue {
  const value = useContext(MobileSessionContext);
  if (value === null) {
    throw new Error(
      "useMobileSession must be rendered within MobileSessionProvider.",
    );
  }

  return value;
}
