/* RK React Builder — Print Studio direction: precise, asymmetric, paper-and-ink editor primitives. */
export type ThemeConfig = {
  primary: string;
  bg: string;
  ink: string;
  font: "Space Grotesk" | "IBM Plex Mono" | "Georgia";
  logo?: string;
};

export type BlockType = "hero" | "heading" | "text" | "image" | "cta" | "services" | "portfolio" | "spacer";
export type Block = { id: string; type: BlockType; [key: string]: string | number | undefined };
export type Layout = { version: number; blocks: Block[] };
export type ContentItem = { id: number; title: string; excerpt: string; image?: string };

export const DEMO_THEME: ThemeConfig = { primary: "#C7F36B", bg: "#F8F5ED", ink: "#1B2430", font: "Space Grotesk" };
export const DEMO_CONTENT: { services: ContentItem[]; portfolio: ContentItem[] } = {
  services: [
    { id: 1, title: "Residential Wiring", excerpt: "Safe, code-compliant installs for homes." },
    { id: 2, title: "Solar & Battery", excerpt: "Design and install of PV + storage." },
    { id: 3, title: "EV Charging", excerpt: "Level-2 chargers, permits handled." },
    { id: 4, title: "Maintenance", excerpt: "Inspections and rapid callouts." },
  ],
  portfolio: [
    { id: 1, title: "Maui Beach House", excerpt: "Full rewire + solar.", image: "/manus-storage/rk-builder-portfolio-grid_1ffd8781.jpg" },
    { id: 2, title: "Downtown Retail", excerpt: "Panel upgrade, LED retrofit.", image: "/manus-storage/rk-builder-service-grid_ab9a493a.jpg" },
    { id: 3, title: "Warehouse EV Bay", excerpt: "12-charger install." },
  ],
};

const id = () => Math.random().toString(36).slice(2, 9);
export const makeBlock = (type: BlockType): Block => {
  const defaults: Record<BlockType, Omit<Block, "id" | "type">> = {
    hero: { heading: "Powering what's next", sub: "Licensed electrical & energy contractors serving the islands.", cta: "Get a quote", ctaHref: "/contact" },
    heading: { text: "Section heading" },
    text: { text: "Write a short paragraph of body copy here." },
    image: { url: "/manus-storage/rk-builder-hero-texture_2b0d6f76.jpg", alt: "Editorial workspace texture" },
    cta: { heading: "Ready to start your project?", cta: "Contact us" },
    services: { title: "Our Services", source: "services", cols: 3 },
    portfolio: { title: "Recent Work", source: "portfolio", cols: 3 },
    spacer: { h: 40 },
  };
  return { id: id(), type, ...defaults[type] };
};
export const demoLayout: Layout = { version: 1, blocks: [makeBlock("hero"), makeBlock("services"), makeBlock("portfolio"), makeBlock("cta")] };

export const blockMeta: Record<BlockType, { label: string; icon: string; description: string }> = {
  hero: { label: "Hero", icon: "↗", description: "Lead with a clear proposition" },
  heading: { label: "Heading", icon: "H", description: "Create hierarchy" },
  text: { label: "Text", icon: "¶", description: "Add body copy" },
  image: { label: "Image", icon: "▧", description: "Bring in a media asset" },
  cta: { label: "CTA banner", icon: "→", description: "Close with an action" },
  services: { label: "Services grid", icon: "⊞", description: "Live Service CPTs" },
  portfolio: { label: "Portfolio grid", icon: "▦", description: "Live Portfolio CPTs" },
  spacer: { label: "Spacer", icon: "↕", description: "Tune vertical rhythm" },
};

const wpBase = () => (localStorage.getItem("rk_wp_base") || "").replace(/\/$/, "");
async function wpFetch<T>(path: string, options?: RequestInit): Promise<T> {
  const base = wpBase();
  if (!base) throw new Error("WordPress base URL is not configured");
  const res = await fetch(`${base}${path}`, { ...options, headers: { "Content-Type": "application/json", ...(options?.headers || {}) } });
  if (!res.ok) throw new Error(`WordPress request failed (${res.status})`);
  return res.json() as Promise<T>;
}
export const wpApi = {
  async load(pageId: string) {
    const [layout, theme] = await Promise.all([wpFetch<Layout>(`/wp-json/rk/v1/builder/layout/${pageId}`), wpFetch<ThemeConfig>("/wp-json/rk/v1/theme-config")]);
    return { layout, theme };
  },
  saveLayout(pageId: string, layout: Layout) { return wpFetch(`/wp-json/rk/v1/builder/layout/${pageId}`, { method: "POST", body: JSON.stringify(layout) }); },
  saveTheme(theme: ThemeConfig) { return wpFetch("/wp-json/rk/v1/theme-config", { method: "POST", body: JSON.stringify(theme) }); },
};
