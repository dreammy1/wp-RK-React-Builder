import {
  contentKey,
  type ContentQuery,
  type ContentResult,
  type ContentSource,
} from "@/render/content";
import { DEMO_CONTENT } from "@/lib/fixtures/demoContent";
import { describeError, isApiError } from "./errors";
import { api } from "./builder";

const TTL_MS = 60_000;

/** The newest images of the media library, shaped like content entries (the editor shows at most 24). */
async function fetchMedia(q: ContentQuery) {
  const r = await api.listMedia(q.category, undefined, 1);
  const items = r.items.slice(0, q.limit).map(m => ({
    id: m.id,
    title: m.alt || m.title,
    excerpt: "",
    link: "",
    categories: [],
    image: {
      url: m.url,
      width: m.width ?? 0,
      height: m.height ?? 0,
      alt: m.alt,
    },
  }));
  return { items, total: items.length };
}
const LOADING: ContentResult = { status: "loading", items: [], total: 0 };

type Entry = { result: ContentResult; at: number; inflight?: Promise<void> };

/** Cache + in-flight de-duplication for live WordPress content used by the editor canvas. */
export class ContentStore implements ContentSource {
  private entries = new Map<string, Entry>();
  private listeners = new Set<() => void>();
  private version = 0;
  constructor(
    private fetcher: (
      q: ContentQuery
    ) => Promise<{ items: ContentResult["items"]; total: number }> = q =>
      q.source === "media" ? fetchMedia(q) : api.listContent(q),
    private demo = false,
    private now: () => number = Date.now
  ) {}

  subscribe = (fn: () => void) => {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  };
  getVersion = () => this.version;
  private emit() {
    this.version++;
    this.listeners.forEach(l => l());
  }

  /** Returns the cached result immediately and kicks off a (deduplicated) fetch when stale. */
  get(query: ContentQuery): ContentResult {
    const key = contentKey(query);
    const entry = this.entries.get(key);
    const fresh = entry && this.now() - entry.at < TTL_MS;
    if (entry && (fresh || entry.inflight)) return entry.result;
    queueMicrotask(() => void this.load(query, key));
    return entry?.result ?? LOADING;
  }

  invalidate() {
    this.entries.clear();
    this.emit();
  }

  private load(query: ContentQuery, key: string): Promise<void> {
    const existing = this.entries.get(key);
    if (existing?.inflight) return existing.inflight;
    const run = async () => {
      await Promise.resolve();
      try {
        const data = this.demo
          ? this.fromDemo(query)
          : await this.fetcher(query);
        this.entries.set(key, {
          result: { status: "ready", items: data.items, total: data.total },
          at: this.now(),
        });
      } catch (e) {
        this.entries.set(key, {
          result: {
            status: "error",
            items: [],
            total: 0,
            error: isApiError(e) ? describeError(e) : String(e),
          },
          at: this.now(),
        });
      }
      this.emit();
    };
    const inflight = run();
    this.entries.set(key, {
      result: existing?.result ?? LOADING,
      at: existing?.at ?? 0,
      inflight,
    });
    return inflight;
  }

  private fromDemo(q: ContentQuery) {
    if (q.source === "media") return { items: [], total: 0 };
    const all = DEMO_CONTENT[q.source].filter(
      i => !q.category || i.categories.includes(q.category)
    );
    return { items: all.slice(0, q.limit), total: all.length };
  }
}
