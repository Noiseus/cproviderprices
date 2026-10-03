"use client";

import { useEffect } from "react";

/** One scoped request at a time; never polls the shell or while hidden. */
export function useImportPolling(
  active: boolean,
  refresh: (signal: AbortSignal) => Promise<void>,
) {
  useEffect(() => {
    if (!active) return;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let disposed = false;
    const schedule = () => {
      if (!disposed && !document.hidden)
        timer = setTimeout(() => void tick(), 2000);
    };
    const tick = async () => {
      if (disposed || document.hidden) return;
      const request = new AbortController();
      controller = request;
      try {
        await refresh(request.signal);
      } finally {
        if (controller === request && !request.signal.aborted) schedule();
      }
    };
    const visibility = () => {
      clearTimeout(timer);
      if (document.hidden) controller?.abort();
      else {
        controller?.abort();
        void tick();
      }
    };
    schedule();
    document.addEventListener("visibilitychange", visibility);
    return () => {
      disposed = true;
      clearTimeout(timer);
      controller?.abort();
      document.removeEventListener("visibilitychange", visibility);
    };
  }, [active, refresh]);
}
