import { ExternalLink, Settings, Sparkles } from "lucide-react";
import { getBoot } from "@/lib/boot";
import type { DashView } from "./Dashboard";

/** Phone-only menu for the sections that do not fit in the tab bar. */
export function MoreSection({ go }: { go: (v: DashView) => void }) {
  const boot = getBoot();
  return (
    <>
      <header className="dash-head">
        <h1>More</h1>
      </header>
      <div className="more-list">
        <button className="more-item" onClick={() => go("site")}>
          <Settings size={16} aria-hidden="true" /> Site &amp; SEO
        </button>
        <button className="more-item" onClick={() => go("visualizer")}>
          <Sparkles size={16} aria-hidden="true" /> Visualizer
        </button>
        {boot?.publicSiteUrl && (
          <a
            className="more-item"
            href={boot.publicSiteUrl}
            target="_blank"
            rel="noreferrer"
          >
            <ExternalLink size={16} aria-hidden="true" /> View site
          </a>
        )}
        {boot?.adminUrl && (
          <a
            className="more-item"
            href={boot.adminUrl.replace(/admin\.php.*$/, "")}
          >
            <ExternalLink size={16} aria-hidden="true" /> WordPress admin
          </a>
        )}
      </div>
    </>
  );
}
