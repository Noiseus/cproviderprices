// @vitest-environment jsdom
import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  useVisibleInterval,
  useVisibleStaleRefresh,
} from "./useVisibleRefresh";

function setHidden(hidden: boolean) {
  Object.defineProperty(document, "hidden", {
    configurable: true,
    value: hidden,
  });
  document.dispatchEvent(new Event("visibilitychange"));
}

afterEach(() => {
  setHidden(false);
  vi.useRealTimers();
});

describe("visible refresh lifecycle", () => {
  it("marks comparison data stale after 60 seconds, pauses hidden work, and resumes with one request", async () => {
    vi.useFakeTimers();
    const refresh = vi.fn().mockResolvedValue(true);
    const { result } = renderHook(() =>
      useVisibleStaleRefresh(60_000, refresh),
    );
    act(() => result.current.markFresh());
    act(() => vi.advanceTimersByTime(60_000));
    expect(result.current.isStale).toBe(true);
    expect(refresh).not.toHaveBeenCalled();

    setHidden(true);
    act(() => vi.advanceTimersByTime(120_000));
    expect(refresh).not.toHaveBeenCalled();
    setHidden(false);
    await act(async () => {});
    expect(refresh).toHaveBeenCalledTimes(1);

    setHidden(true);
    setHidden(false);
    await act(async () => {});
    expect(refresh).toHaveBeenCalledTimes(1);
  });

  it("refreshes the dashboard only every documented visible 60-second cadence", () => {
    vi.useFakeTimers();
    const refresh = vi.fn();
    renderHook(() => useVisibleInterval(60_000, refresh));
    act(() => vi.advanceTimersByTime(59_999));
    expect(refresh).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(refresh).toHaveBeenCalledTimes(1);
    setHidden(true);
    act(() => vi.advanceTimersByTime(120_000));
    expect(refresh).toHaveBeenCalledTimes(1);
    setHidden(false);
    act(() => vi.advanceTimersByTime(0));
    expect(refresh).toHaveBeenCalledTimes(2);
    act(() => vi.advanceTimersByTime(59_999));
    expect(refresh).toHaveBeenCalledTimes(2);
  });
});
