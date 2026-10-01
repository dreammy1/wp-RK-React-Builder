const SENSITIVE =
  /pass(word)?|token|secret|authorization|cookie|nonce|dsn|csrf/i;

export function redact(value: unknown, depth = 0): unknown {
  if (depth > 5) return "[depth]";
  if (Array.isArray(value)) return value.map(v => redact(v, depth + 1));
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([k, v]) => [
        k,
        SENSITIVE.test(k) ? "[redacted]" : redact(v, depth + 1),
      ])
    );
  }
  if (typeof value === "string" && value.length > 300)
    return `${value.slice(0, 300)}…`;
  return value;
}

type Level = "info" | "warn" | "error";
export type Sink = (line: string) => void;
let sink: Sink = line => process.stdout.write(line + "\n");
export const setLogSink = (s: Sink) => {
  sink = s;
};

/** One JSON object per line. Layout bodies, headers and credentials are never passed in. */
export function log(
  level: Level,
  event: string,
  fields: Record<string, unknown> = {}
) {
  sink(
    JSON.stringify({
      t: new Date().toISOString(),
      level,
      event,
      ...(redact(fields) as object),
    })
  );
}
