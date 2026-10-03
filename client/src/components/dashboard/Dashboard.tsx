import { useState, type ComponentType } from "react";
import {
  ExternalLink,
  FileText,
  Image as ImageIcon,
  LayoutDashboard,
  MoreHorizontal,
  Package,
  Settings,
  Sparkles,
  Star,
  CodeXml,
  Shuffle,
  Database,
  Boxes,
  LayoutTemplate,
  SlidersHorizontal,
} from "lucide-react";
import { api } from "@/lib/api/builder";
import { getBoot } from "@/lib/boot";
import { CodeSection } from "./CodeSection";
import { GlobalSection } from "./GlobalSection";
import { ContentSection } from "./ContentSection";
import { TemplatesSection } from "./TemplatesSection";
import { TypesSection } from "./TypesSection";
import { MediaSection } from "./MediaSection";
import { MoreSection } from "./MoreSection";
import { Overview } from "./Overview";
import { PagesSection } from "./PagesSection";
import { RedirectsSection } from "./RedirectsSection";
import { ReviewsSection } from "./ReviewsSection";
import { SiteSection } from "./SiteSection";
import { ThemesSection } from "./ThemesSection";
import { VisualizerSection } from "./VisualizerSection";

export type DashView =
  | "overview"
  | "pages"
  | "content"
  | "media"
  | "templates"
  | "types"
  | "themes"
  | "site"
  | "global"
  | "reviews"
  | "code"
  | "redirects"
  | "visualizer"
  | "more";

type Icon = ComponentType<{ size?: number; "aria-hidden"?: boolean }>;
const NAV: { id: DashView; label: string; icon: Icon }[] = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "pages", label: "Pages", icon: FileText },
  { id: "content", label: "Content", icon: Database },
  { id: "media", label: "Media", icon: ImageIcon },
  { id: "templates", label: "Templates", icon: LayoutTemplate },
  { id: "types", label: "Types & fields", icon: Boxes },
  { id: "themes", label: "Themes", icon: Package },
  { id: "site", label: "Site & SEO", icon: Settings },
  { id: "global", label: "Global settings", icon: SlidersHorizontal },
  { id: "reviews", label: "Reviews", icon: Star },
  { id: "code", label: "Code & tracking", icon: CodeXml },
  { id: "redirects", label: "Redirects", icon: Shuffle },
  { id: "visualizer", label: "Visualizer", icon: Sparkles },
];
/** The phone tab bar has room for five: the rest live under More. */
const TABS: { id: DashView; label: string; icon: Icon }[] = [
  NAV[0]!,
  NAV[1]!,
  NAV[2]!,
  NAV[3]!,
  { id: "more", label: "More", icon: MoreHorizontal },
];

/** Sections that need an administrator (the API refuses everyone else). */
const ADMIN_ONLY = new Set<DashView>([
  "templates",
  "types",
  "themes",
  "site",
  "global",
  "reviews",
  "code",
  "redirects",
  "visualizer",
]);

const VIEWS = new Set<string>([...NAV.map(n => n.id), "more"]);

function readView(): DashView {
  const v = new URLSearchParams(location.search).get("view") ?? "";
  return VIEWS.has(v) ? (v as DashView) : "overview";
}

/** The builder's own dashboard: pages, media, themes, site settings and the visualizer in one place. */
export function Dashboard({ navigate }: { navigate: (to: string) => void }) {
  const admin = api.canTransferSite();
  const [view, setViewState] = useState<DashView>(() => {
    const v = readView();
    return !admin && ADMIN_ONLY.has(v) ? "overview" : v;
  });
  const boot = getBoot();
  const [contentType, setContentType] = useState<string | undefined>();
  const items = NAV.filter(n => admin || !ADMIN_ONLY.has(n.id));
  const tabs = admin
    ? TABS
    : [
        ...items.slice(0, 4),
        { id: "more" as DashView, label: "More", icon: MoreHorizontal },
      ];

  const setView = (v: DashView) => {
    setViewState(v);
    const u = new URL(location.href);
    u.searchParams.set("view", v);
    history.replaceState(null, "", u);
    window.scrollTo(0, 0);
  };

  const body = {
    overview: <Overview go={setView} navigate={navigate} admin={admin} />,
    pages: <PagesSection navigate={navigate} />,
    content: <ContentSection key={contentType} initialType={contentType} />,
    media: <MediaSection />,
    templates: <TemplatesSection navigate={navigate} />,
    types: (
      <TypesSection
        openContent={slug => {
          setContentType(slug);
          setView("content");
        }}
      />
    ),
    themes: <ThemesSection />,
    site: <SiteSection />,
    global: <GlobalSection />,
    reviews: <ReviewsSection />,
    code: <CodeSection />,
    redirects: <RedirectsSection />,
    visualizer: <VisualizerSection />,
    more: <MoreSection go={setView} admin={admin} />,
  }[view];

  const nav = (id: DashView) =>
    view === id ||
    (id === "more" &&
      [
        "templates",
        "types",
        "themes",
        "site",
        "global",
        "reviews",
        "code",
        "redirects",
        "visualizer",
      ].includes(view));

  return (
    <div className="dash">
      <aside className="dash-nav" aria-label="Dashboard">
        <div className="dash-brand">
          <span className="eyebrow">RK / BUILDER</span>
          <strong>Dashboard</strong>
        </div>
        <nav>
          {items.map(n => (
            <button
              key={n.id}
              className={view === n.id ? "active" : ""}
              aria-current={view === n.id ? "page" : undefined}
              onClick={() => setView(n.id)}
            >
              <n.icon size={16} aria-hidden={true} /> {n.label}
            </button>
          ))}
        </nav>
        <div className="dash-links">
          {boot?.publicSiteUrl && (
            <a href={boot.publicSiteUrl} target="_blank" rel="noreferrer">
              <ExternalLink size={14} aria-hidden="true" /> View site
            </a>
          )}
          {boot?.adminUrl && (
            <a href={boot.adminUrl.replace(/admin\.php.*$/, "")}>
              <ExternalLink size={14} aria-hidden="true" /> WordPress admin
            </a>
          )}
        </div>
      </aside>
      <main className="dash-main">{body}</main>
      <nav className="bottom-nav dash-tabs" aria-label="Dashboard">
        {tabs.map(t => (
          <button
            key={t.id}
            className={nav(t.id) ? "on" : ""}
            aria-current={nav(t.id) ? "page" : undefined}
            onClick={() => setView(t.id)}
          >
            <t.icon size={20} aria-hidden={true} />
            <span>{t.label}</span>
          </button>
        ))}
      </nav>
    </div>
  );
}
