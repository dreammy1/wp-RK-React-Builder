import { getBoot } from "./boot";

type Event = {
  type: "vitals" | "js_error" | "load_failure";
  detail?: string;
  lcp?: number;
  cls?: number;
};

/** Fire-and-forget beacon to this app's /api/telemetry (standalone mode only). Never carries layout data. */
export function sendTelemetry(event: Event) {
  if (getBoot()) return; // embedded in wp-admin: the telemetry endpoint is on another origin
  try {
    const body = JSON.stringify({
      ...event,
      path: location.pathname,
      detail: event.detail?.slice(0, 200),
    });
    navigator.sendBeacon(
      "/api/telemetry",
      new Blob([body], { type: "application/json" })
    );
  } catch {
    /* telemetry must never break the app */
  }
}

export function installTelemetry() {
  window.addEventListener("error", e =>
    sendTelemetry({ type: "js_error", detail: e.message })
  );
  window.addEventListener("unhandledrejection", e =>
    sendTelemetry({
      type: "js_error",
      detail: String((e.reason as Error)?.message ?? e.reason),
    })
  );
  let lcp = 0;
  let cls = 0;
  try {
    new PerformanceObserver(list => {
      const entries = list.getEntries();
      lcp = entries[entries.length - 1]?.startTime ?? lcp;
    }).observe({ type: "largest-contentful-paint", buffered: true });
    new PerformanceObserver(list => {
      for (const e of list.getEntries() as unknown as {
        value: number;
        hadRecentInput: boolean;
      }[]) {
        if (!e.hadRecentInput) cls += e.value;
      }
    }).observe({ type: "layout-shift", buffered: true });
  } catch {
    /* unsupported browser */
  }
  let sent = false;
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden" && !sent) {
      sent = true;
      sendTelemetry({
        type: "vitals",
        lcp: Math.round(lcp),
        cls: Math.round(cls * 1000) / 1000,
      });
    }
  });
}
