"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Keeps a single view-local timer/request lifecycle. It deliberately has no
 * global cache or polling loop: stale data is refreshed only when the view is
 * visible and eligible, while a user can always request a local refresh.
 */
export function useVisibleStaleRefresh(
  ttlMs: number,
  refresh: (signal: AbortSignal) => Promise<boolean>,
  enabled = true,
) {
  const refreshRef = useRef(refresh);
  const requestRef = useRef<AbortController | null>(null);
  const lastUpdatedRef = useRef<number | null>(null);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<number | null>(null);
  const [isStale, setIsStale] = useState(false);

  useEffect(() => {
    refreshRef.current = refresh;
  }, [refresh]);

  const markFresh = useCallback(() => {
    const updated = Date.now();
    lastUpdatedRef.current = updated;
    setLastUpdatedAt(updated);
    setIsStale(false);
  }, []);

  const refreshNow = useCallback(async () => {
    if (requestRef.current && !requestRef.current.signal.aborted) return false;
    const controller = new AbortController();
    requestRef.current = controller;
    try {
      const succeeded = await refreshRef.current(controller.signal);
      if (succeeded && !controller.signal.aborted) markFresh();
      return succeeded;
    } finally {
      if (requestRef.current === controller) requestRef.current = null;
    }
  }, [markFresh]);

  useEffect(() => {
    if (!enabled || lastUpdatedAt === null) return;
    let timer: number | undefined;
    let disposed = false;
    const check = () => {
      if (disposed || document.hidden || lastUpdatedRef.current === null)
        return;
      const remaining = lastUpdatedRef.current + ttlMs - Date.now();
      if (remaining <= 0) {
        setIsStale(true);
        return;
      }
      timer = window.setTimeout(check, remaining);
    };
    const visibility = () => {
      window.clearTimeout(timer);
      if (document.hidden) return;
      if (
        lastUpdatedRef.current !== null &&
        Date.now() >= lastUpdatedRef.current + ttlMs
      ) {
        setIsStale(true);
        void refreshNow();
      } else {
        check();
      }
    };
    check();
    document.addEventListener("visibilitychange", visibility);
    return () => {
      disposed = true;
      window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", visibility);
    };
  }, [enabled, lastUpdatedAt, refreshNow, ttlMs]);

  useEffect(
    () => () => {
      requestRef.current?.abort();
    },
    [],
  );

  return { isStale, lastUpdatedAt, markFresh, refreshNow };
}

/** A visible-only periodic callback, used only where a documented cadence exists. */
export function useVisibleInterval(
  intervalMs: number,
  callback: () => void,
  enabled = true,
) {
  const callbackRef = useRef(callback);
  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);
  useEffect(() => {
    if (!enabled) return;
    let timer: number | undefined;
    let disposed = false;
    let lastRun = Date.now();
    const run = () => {
      if (disposed || document.hidden) return;
      lastRun = Date.now();
      callbackRef.current();
      timer = window.setTimeout(run, intervalMs);
    };
    const visibility = () => {
      window.clearTimeout(timer);
      if (document.hidden) return;
      const elapsed = Date.now() - lastRun;
      timer = window.setTimeout(run, Math.max(0, intervalMs - elapsed));
    };
    timer = window.setTimeout(run, intervalMs);
    document.addEventListener("visibilitychange", visibility);
    return () => {
      disposed = true;
      window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", visibility);
    };
  }, [enabled, intervalMs]);
}
