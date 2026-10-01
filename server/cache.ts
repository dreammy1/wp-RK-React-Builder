type Entry<T> = { value: T; fresh: number; stale: number; slug: string | null };

/** In-memory TTL cache with stale-if-error and slug/global invalidation. One process; see DEPLOYMENT.md for scaling notes. */
export class PageCache<T> {
  private map = new Map<string, Entry<T>>();
  constructor(
    private ttlMs: number,
    private staleMs = 60 * 60 * 1000,
    private now: () => number = Date.now,
    private maxEntries = 500
  ) {}
  get(key: string): { value: T; fresh: boolean } | null {
    const e = this.map.get(key);
    if (!e) return null;
    const t = this.now();
    if (t > e.stale) {
      this.map.delete(key);
      return null;
    }
    return { value: e.value, fresh: t <= e.fresh };
  }
  set(key: string, value: T, slug: string | null = null) {
    if (this.ttlMs <= 0) return;
    if (this.map.size >= this.maxEntries)
      this.map.delete(this.map.keys().next().value!);
    const t = this.now();
    this.map.set(key, {
      value,
      fresh: t + this.ttlMs,
      stale: t + this.ttlMs + this.staleMs,
      slug,
    });
  }
  purgeSlug(slug: string) {
    for (const [k, e] of this.map) if (e.slug === slug) this.map.delete(k);
  }
  purgeAll() {
    this.map.clear();
  }
  get size() {
    return this.map.size;
  }
}
