import type {
  MobileLoginResponse,
  ProductComparisonResponse,
  PriceHistoryResponse,
  ProductSearchResponse,
  RestaurantLocationListResponse,
  SavedListDetail,
  SavedListIndexResponse,
  Session,
} from "@/types/api";
import { MobileApiError } from "@/lib/errors";
import type { MobileAuthStorage } from "@/lib/auth-storage";

type FetchLike = typeof fetch;

interface ApiEnvelopeError {
  error?: {
    code?: string;
    request_id?: string;
    details?: Record<string, unknown>;
  };
}

export interface MobileApiClientOptions {
  baseUrl: string;
  authStorage: MobileAuthStorage;
  fetchImpl?: FetchLike;
  requestId?: () => string;
}

function createRequestId(): string {
  return (
    globalThis.crypto?.randomUUID?.() ??
    `mobile-${Date.now()}-${Math.random().toString(16).slice(2)}`
  );
}

function queryString(
  values: Record<string, string | number | readonly string[] | undefined>,
): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(values)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        query.append(key, item);
      }
    } else if (value !== undefined && value !== "") {
      query.set(key, String(value));
    }
  }

  const rendered = query.toString();
  return rendered === "" ? "" : `?${rendered}`;
}

export function createMobileApiClient(options: MobileApiClientOptions) {
  const baseUrl = options.baseUrl.replace(/\/$/, "");
  const fetchImpl = options.fetchImpl ?? fetch;
  const requestId = options.requestId ?? createRequestId;

  async function request<T>(
    path: string,
    init: RequestInit = {},
    authenticated = true,
  ): Promise<T> {
    if (baseUrl === "") {
      throw new MobileApiError("network_error", null, null);
    }

    const headers = new Headers(init.headers);
    headers.set("Accept", "application/json");
    headers.set("X-Request-ID", requestId());

    if (init.body !== undefined) {
      headers.set("Content-Type", "application/json");
    }

    if (authenticated) {
      const auth = await options.authStorage.read();
      if (auth === null) {
        throw new MobileApiError("mobile_token_expired", 401, null);
      }
      headers.set("Authorization", `Bearer ${auth.accessToken}`);
    }

    let response: Response;
    try {
      response = await fetchImpl(`${baseUrl}${path}`, { ...init, headers });
    } catch {
      throw new MobileApiError("network_error", null, null);
    }

    const body: unknown = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = body as ApiEnvelopeError;
      const code = error.error?.code ?? "request_failed";
      if (code === "mobile_token_expired" || code === "mobile_token_invalid") {
        await options.authStorage.clear();
      }
      throw new MobileApiError(
        code,
        response.status,
        error.error?.request_id ?? response.headers.get("X-Request-ID"),
        error.error?.details ?? null,
      );
    }

    return body as T;
  }

  return {
    login: (
      email: string,
      password: string,
      deviceName: string,
    ): Promise<MobileLoginResponse> =>
      request<MobileLoginResponse>(
        "/mobile/auth/login",
        {
          method: "POST",
          body: JSON.stringify({ email, password, device_name: deviceName }),
        },
        false,
      ),
    session: async (): Promise<Session> =>
      (await request<{ session: Session }>("/auth/session")).session,
    logout: async (): Promise<void> => {
      await request<{ message: string }>("/auth/session", { method: "DELETE" });
    },
    updateLanguage: async (preferredLanguage: "en" | "es"): Promise<Session> =>
      (
        await request<{ session: Session }>("/auth/preferences", {
          method: "PATCH",
          body: JSON.stringify({ preferred_language: preferredLanguage }),
        })
      ).session,
    locations: (
      organizationId: string,
    ): Promise<RestaurantLocationListResponse> =>
      request(`/organizations/${organizationId}/restaurant-locations`),
    search: (
      organizationId: string,
      params: {
        q: string;
        locationId: string;
        sort?: string;
        freshness?: readonly string[];
      },
    ): Promise<ProductSearchResponse> =>
      request(
        `/organizations/${organizationId}/product-search${queryString({
          q: params.q,
          location_id: params.locationId,
          sort: params.sort,
          "freshness[]": params.freshness,
        })}`,
      ),
    comparison: (
      organizationId: string,
      canonicalProductId: string,
      locationId: string,
    ): Promise<ProductComparisonResponse> =>
      request(
        `/organizations/${organizationId}/comparisons/${canonicalProductId}${queryString({ location_id: locationId })}`,
      ),
    priceHistory: (
      organizationId: string,
      canonicalProductId: string,
      locationId: string,
    ): Promise<PriceHistoryResponse> =>
      request(
        `/organizations/${organizationId}/price-history/${canonicalProductId}${queryString({ location_id: locationId })}`,
      ),
    savedLists: (
      organizationId: string,
      locationId: string,
    ): Promise<SavedListIndexResponse> =>
      request(
        `/organizations/${organizationId}/saved-lists${queryString({ location_id: locationId })}`,
      ),
    savedList: async (
      organizationId: string,
      savedListId: string,
    ): Promise<SavedListDetail> =>
      (
        await request<{ saved_list: SavedListDetail }>(
          `/organizations/${organizationId}/saved-lists/${savedListId}`,
        )
      ).saved_list,
  };
}
