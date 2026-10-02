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
} from "lucide-react";
import { getBoot } from "@/lib/boot";
import { MediaSection } from "./MediaSection";
import { MoreSection } from "./MoreSection";
import { Overview } from "./Overview";
import { PagesSection } from "./PagesSection";
import { SiteSection } from "./SiteSection";
import { ThemesSection } from "./ThemesSection";
import { VisualizerSection } from "./VisualizerSection";

export type DashView =
  "overview" | "pages" | "media" | "themes" | "site" | "visualizer" | "more";

type Icon = ComponentType<{ size?: number; "aria-hidden"?: boolean }>;
const NAV: { id: DashView; label: string; icon: Icon }[] = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "pages", label: "Pages", icon: FileText },
  { id: "media", label: "Media", icon: ImageIcon },
  { id: "themes", label: "Themes", icon: Package },
  { id: "site", label: "Site & SEO", icon: Settings },
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

const VIEWS = new Set<string>([...NAV.map(n => n.id), "more"]);

function readView(): DashView {
  const v = new URLSearchParams(location.search).get("view") ?? "";
  return VIEWS.has(v) ? (v as DashView) : "overview";
}

/** The builder's own dashboard: pages, media, themes, site settings and the visualizer in one place. */
export function Dashboard({ navigate }: { navigate: (to: string) => void }) {
  const [view, setViewState] = useState<DashView>(readView);
  const boot = getBoot();

  const setView = (v: DashView) => {
    setViewState(v);
    const u = new URL(location.href);
    u.searchParams.set("view", v);
    history.replaceState(null, "", u);
    window.scrollTo(0, 0);
  };

  const body = {
    overview: <Overview go={setView} navigate={navigate} />,
    pages: <PagesSection navigate={navigate} />,
    media: <MediaSection />,
    themes: <ThemesSection />,
    site: <SiteSection />,
    visualizer: <VisualizerSection />,
    more: <MoreSection go={setView} />,
  }[view];

  const nav = (id: DashView) =>
    view === id ||
    (id === "more" && (view === "site" || view === "visualizer"));

  return (
    <div className="dash">
      <aside className="dash-nav" aria-label="Dashboard">
        <div className="dash-brand">
          <span className="eyebrow">RK / BUILDER</span>
          <strong>Dashboard</strong>
        </div>
        <nav>
          {NAV.map(n => (
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
        {TABS.map(t => (
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
