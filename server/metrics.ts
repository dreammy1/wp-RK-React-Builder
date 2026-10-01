/** Tiny in-process counters rendered as Prometheus text. Labels are low-cardinality by construction. */
export class Metrics {
  private counters = new Map<string, number>();
  private sums = new Map<string, { sum: number; count: number }>();
  inc(name: string, labels: Record<string, string | number> = {}) {
    const key = this.key(name, labels);
    this.counters.set(key, (this.counters.get(key) ?? 0) + 1);
  }
  observe(
    name: string,
    value: number,
    labels: Record<string, string | number> = {}
  ) {
    const key = this.key(name, labels);
    const cur = this.sums.get(key) ?? { sum: 0, count: 0 };
    this.sums.set(key, { sum: cur.sum + value, count: cur.count + 1 });
  }
  private key(name: string, labels: Record<string, string | number>) {
    const l = Object.entries(labels)
      .map(([k, v]) => `${k}="${String(v).replace(/[^a-zA-Z0-9_.:-]/g, "_")}"`)
      .join(",");
    return l ? `${name}{${l}}` : name;
  }
  render(): string {
    const out: string[] = [];
    for (const [k, v] of this.counters) out.push(`${k} ${v}`);
    for (const [k, v] of this.sums) {
      const [name, labels = ""] = k.split("{");
      const suffix = labels ? `{${labels}` : "";
      out.push(
        `${name}_sum${suffix} ${v.sum}`,
        `${name}_count${suffix} ${v.count}`
      );
    }
    return out.join("\n") + "\n";
  }
}
