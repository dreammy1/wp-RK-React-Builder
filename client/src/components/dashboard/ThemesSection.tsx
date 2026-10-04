import { useState } from "react";
import { ExportSiteButton, ImportSiteDialog } from "../SiteTransfer";
import { ThemeEngine } from "../ThemeEngine";
import { KitLibrary } from "./KitLibrary";
import { Upload } from "lucide-react";

export function ThemesSection() {
  const [importing, setImporting] = useState(false);
  const [rev, setRev] = useState(0);
  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Themes</h1>
          <p className="muted">
            Package this site as a reusable theme, install one in a click, or
            move the whole site with a file.
          </p>
        </div>
      </header>
      <KitLibrary onAdded={() => setRev(r => r + 1)} />
      <section className="dash-card">
        <ThemeEngine key={rev} onInstalled={() => undefined} />
      </section>
      <section className="dash-card">
        <h2>Backup &amp; transfer</h2>
        <p className="muted">
          Download every page, block, image reference, theme setting and SEO
          field as one file, or import such a file here.
        </p>
        <div className="dash-actions">
          <ExportSiteButton />
          <button className="top-btn" onClick={() => setImporting(true)}>
            <Upload size={14} aria-hidden="true" /> Import site
          </button>
        </div>
      </section>
      {importing && (
        <ImportSiteDialog
          onClose={() => setImporting(false)}
          onImported={() => undefined}
        />
      )}
    </>
  );
}
