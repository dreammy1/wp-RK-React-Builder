import {
  Boxes,
  CodeXml,
  LayoutTemplate,
  Package,
  ExternalLink,
  Settings,
  SlidersHorizontal,
  Shuffle,
  Sparkles,
  Star,
} from "lucide-react";
import { getBoot } from "@/lib/boot";
import type { DashView } from "./Dashboard";

/** Phone-only menu for the sections that do not fit in the tab bar. */
export function MoreSection({
  go,
  admin,
}: {
  go: (v: DashView) => void;
  admin: boolean;
}) {
  const boot = getBoot();
  return (
    <>
      <header className="dash-head">
        <h1>More</h1>
      </header>
      <div className="more-list">
        {admin && (
          <>
            <button className="more-item" onClick={() => go("templates")}>
              <LayoutTemplate size={16} aria-hidden="true" /> Templates
            </button>
            <button className="more-item" onClick={() => go("types")}>
              <Boxes size={16} aria-hidden="true" /> Types &amp; fields
            </button>
            <button className="more-item" onClick={() => go("themes")}>
              <Package size={16} aria-hidden="true" /> Themes
            </button>
            <button className="more-item" onClick={() => go("site")}>
              <Settings size={16} aria-hidden="true" /> Site &amp; SEO
            </button>
            <button className="more-item" onClick={() => go("global")}>
              <SlidersHorizontal size={16} aria-hidden="true" /> Global settings
            </button>
            <button className="more-item" onClick={() => go("reviews")}>
              <Star size={16} aria-hidden="true" /> Reviews
            </button>
            <button className="more-item" onClick={() => go("code")}>
              <CodeXml size={16} aria-hidden="true" /> Code &amp; tracking
            </button>
            <button className="more-item" onClick={() => go("redirects")}>
              <Shuffle size={16} aria-hidden="true" /> Redirects
            </button>
            <button className="more-item" onClick={() => go("visualizer")}>
              <Sparkles size={16} aria-hidden="true" /> Visualizer
            </button>
          </>
        )}
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
