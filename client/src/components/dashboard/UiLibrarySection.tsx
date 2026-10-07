import { useState, useMemo } from "react";
import {
  Check,
  Compass,
  Copy,
  ExternalLink,
  Eye,
  FilePlus,
  Layers,
  LayoutTemplate,
  Monitor,
  Package,
  Plus,
  Search,
  Smartphone,
  Sparkles,
  Tablet,
  CheckCircle2,
} from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import { pageHref } from "@/lib/router";
import { themeToCssVars, themeToDesignRules } from "@/lib/schema/theme";
import { LayoutRenderer } from "@/render/BlockRenderer";
import { Modal } from "../Modal";
import {
  UI_LIBRARY_ITEMS,
  ARCHETYPES,
  type UiCategory,
  type UiArchetype,
  type UiLibraryItem,
} from "@/data/uiLibraryData";

const CATEGORIES: { id: "all" | UiCategory; label: string; count: number }[] = [
  { id: "all", label: "All Assets", count: 25 },
  { id: "theme", label: "Themes", count: 5 },
  { id: "page", label: "Page Templates", count: 5 },
  { id: "header", label: "Header Templates", count: 5 },
  { id: "footer", label: "Footer Templates", count: 5 },
  { id: "section", label: "Section Templates", count: 5 },
];

export function UiLibrarySection({
  navigate,
}: {
  navigate?: (to: string) => void;
}) {
  const [category, setCategory] = useState<"all" | UiCategory>("all");
  const [archetype, setArchetype] = useState<"all" | UiArchetype>("all");
  const [query, setQuery] = useState("");
  const [previewItem, setPreviewItem] = useState<UiLibraryItem | null>(null);
  const [device, setDevice] = useState<"desktop" | "tablet" | "mobile">("desktop");
  const [busy, setBusy] = useState(false);
  const [notification, setNotification] = useState<{
    tone: "success" | "error";
    text: string;
    actionLink?: string;
    actionLabel?: string;
  } | null>(null);
  const [copiedId, setCopiedId] = useState<string | null>(null);

  const filteredItems = useMemo(() => {
    return UI_LIBRARY_ITEMS.filter(item => {
      if (category !== "all" && item.category !== category) return false;
      if (archetype !== "all" && item.archetype !== archetype) return false;
      if (query.trim()) {
        const q = query.toLowerCase();
        const matchesName = item.name.toLowerCase().includes(q);
        const matchesDesc = item.description.toLowerCase().includes(q);
        const matchesTags = item.tags.some(t => t.toLowerCase().includes(q));
        const matchesArchetype = item.archetypeLabel.toLowerCase().includes(q);
        if (!matchesName && !matchesDesc && !matchesTags && !matchesArchetype) return false;
      }
      return true;
    });
  }, [category, archetype, query]);

  const handleApplyTheme = async (item: UiLibraryItem) => {
    setBusy(true);
    setNotification(null);
    try {
      await api.applyUiLibraryTheme(item.tokens);
      setNotification({
        tone: "success",
        text: `Theme tokens from “${item.name}” applied to your active site successfully!`,
      });
    } catch (e) {
      setNotification({ tone: "error", text: describeError(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleCreatePage = async (item: UiLibraryItem) => {
    if (!item.layout) return;
    setBusy(true);
    setNotification(null);
    try {
      const res = await api.createUiLibraryPage(item.name, item.layout);
      const editUrl = pageHref(res.id);
      setNotification({
        tone: "success",
        text: `Draft page “${item.name}” created!`,
        actionLink: editUrl,
        actionLabel: "Open in Builder",
      });
      if (navigate) {
        navigate(editUrl);
      }
    } catch (e) {
      setNotification({ tone: "error", text: describeError(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleCreateTemplate = async (
    item: UiLibraryItem,
    kind: "header" | "footer",
    activate = false
  ) => {
    if (!item.layout) return;
    setBusy(true);
    setNotification(null);
    try {
      const res = await api.createUiLibraryTemplate(item.name, kind, item.layout, activate);
      const editUrl = pageHref(res.id);
      setNotification({
        tone: "success",
        text: `${kind === "header" ? "Header" : "Footer"} template “${item.name}” created${
          activate ? " & set active site-wide" : ""
        }!`,
        actionLink: editUrl,
        actionLabel: "Edit Template",
      });
    } catch (e) {
      setNotification({ tone: "error", text: describeError(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleCreateReusable = async (item: UiLibraryItem) => {
    const blockToSave = item.block || item.layout?.blocks[0];
    if (!blockToSave) return;
    setBusy(true);
    setNotification(null);
    try {
      await api.createUiLibraryReusable(item.name, blockToSave);
      setNotification({
        tone: "success",
        text: `“${item.name}” added to Reusable Blocks! You can now drop it into any page from the Palette.`,
      });
    } catch (e) {
      setNotification({ tone: "error", text: describeError(e) });
    } finally {
      setBusy(false);
    }
  };

  const handleCopyJson = (item: UiLibraryItem) => {
    const payload = item.layout || item.block || item.tokens;
    navigator.clipboard.writeText(JSON.stringify(payload, null, 2));
    setCopiedId(item.id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  return (
    <div className="ui-library-section">
      <header className="dash-head">
        <div>
          <div className="flex-row gap-2 align-center mb-1">
            <span className="badge badge-accent">NEW FEATURE</span>
            <span className="badge badge-subtle">25 Unique Variants</span>
          </div>
          <h1>UI Library</h1>
          <p className="muted">
            Production-ready, minimal, modern, responsive themes, page templates, headers,
            footers, and modular sections with curated style tokens for RK Builder.
          </p>
        </div>
      </header>

      {notification && (
        <div
          className={`ui-lib-alert ${notification.tone === "success" ? "success" : "error"}`}
          role="status"
        >
          <div className="flex-row gap-2 align-center">
            {notification.tone === "success" && <CheckCircle2 size={16} />}
            <span>{notification.text}</span>
          </div>
          {notification.actionLink && (
            <a
              href={notification.actionLink}
              className="btn btn-sm btn-outline ml-auto"
              onClick={e => {
                if (navigate) {
                  e.preventDefault();
                  navigate(notification.actionLink!);
                }
              }}
            >
              {notification.actionLabel || "View"}
            </a>
          )}
        </div>
      )}

      {/* Filter and Search Bar */}
      <div className="ui-lib-toolbar">
        <div className="ui-lib-categories">
          {CATEGORIES.map(c => (
            <button
              key={c.id}
              className={`ui-lib-cat-btn ${category === c.id ? "active" : ""}`}
              onClick={() => setCategory(c.id)}
            >
              <span>{c.label}</span>
              <span className="ui-lib-cat-count">{c.count}</span>
            </button>
          ))}
        </div>

        <div className="ui-lib-subbar">
          <div className="ui-lib-archetypes">
            <span className="ui-lib-filter-label">Style Archetype:</span>
            <button
              className={`ui-lib-arch-btn ${archetype === "all" ? "active" : ""}`}
              onClick={() => setArchetype("all")}
            >
              All Styles
            </button>
            {(Object.keys(ARCHETYPES) as UiArchetype[]).map(key => (
              <button
                key={key}
                className={`ui-lib-arch-btn ${archetype === key ? "active" : ""}`}
                onClick={() => setArchetype(key)}
              >
                {ARCHETYPES[key].label}
              </button>
            ))}
          </div>

          <div className="ui-lib-search">
            <Search size={14} aria-hidden="true" className="search-icon" />
            <input
              type="search"
              placeholder="Filter by keyword, tags, or archetype…"
              value={query}
              onChange={e => setQuery(e.target.value)}
              aria-label="Search UI Library"
            />
          </div>
        </div>
      </div>

      {/* Item Grid */}
      <div className="ui-lib-grid">
        {filteredItems.map(item => {
          const t = item.tokens;
          return (
            <article key={item.id} className="ui-lib-card">
              <div className="ui-lib-card-head">
                <div className="flex-row gap-1 align-center">
                  <span className={`ui-lib-badge arch-${item.archetype}`}>
                    {item.archetypeLabel}
                  </span>
                  <span className="ui-lib-badge cat-badge">
                    {item.category.toUpperCase()}
                  </span>
                </div>
                <button
                  className="icon-btn"
                  title="Copy JSON configuration"
                  onClick={() => handleCopyJson(item)}
                >
                  {copiedId === item.id ? <Check size={14} /> : <Copy size={14} />}
                </button>
              </div>

              {/* Style token color strip preview */}
              <div className="ui-lib-token-strip" title="Style token color palette">
                <div className="token-swatch" style={{ background: t.primary }} title={`Primary: ${t.primary}`} />
                <div className="token-swatch" style={{ background: t.accent || t.primary }} title={`Accent: ${t.accent || t.primary}`} />
                <div className="token-swatch" style={{ background: t.surface || t.bg }} title={`Surface: ${t.surface || t.bg}`} />
                <div className="token-swatch" style={{ background: t.dark || t.ink }} title={`Dark: ${t.dark || t.ink}`} />
                <div className="token-swatch" style={{ background: t.bg }} title={`Bg: ${t.bg}`} />
              </div>

              <div className="ui-lib-card-body">
                <h3 className="ui-lib-card-title">{item.name}</h3>
                <p className="ui-lib-card-tagline">{item.tagline}</p>
                <p className="ui-lib-card-desc">{item.description}</p>

                <div className="ui-lib-tags">
                  {item.tags.map(tag => (
                    <span key={tag} className="ui-lib-tag">
                      #{tag}
                    </span>
                  ))}
                </div>
              </div>

              <div className="ui-lib-card-footer">
                <button
                  className="btn btn-sm btn-outline"
                  onClick={() => {
                    setPreviewItem(item);
                    setDevice("desktop");
                  }}
                >
                  <Eye size={14} aria-hidden="true" />
                  <span>Preview</span>
                </button>

                {item.category === "theme" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => handleApplyTheme(item)}
                  >
                    <Sparkles size={14} aria-hidden="true" />
                    <span>Apply Theme</span>
                  </button>
                )}

                {item.category === "page" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => handleCreatePage(item)}
                  >
                    <FilePlus size={14} aria-hidden="true" />
                    <span>Create Page</span>
                  </button>
                )}

                {(item.category === "header" || item.category === "footer") && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => handleCreateTemplate(item, item.category as "header" | "footer", true)}
                  >
                    <LayoutTemplate size={14} aria-hidden="true" />
                    <span>Set Site {item.category === "header" ? "Header" : "Footer"}</span>
                  </button>
                )}

                {item.category === "section" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => handleCreateReusable(item)}
                  >
                    <Plus size={14} aria-hidden="true" />
                    <span>Add to Reusables</span>
                  </button>
                )}
              </div>
            </article>
          );
        })}
      </div>

      {filteredItems.length === 0 && (
        <div className="ui-lib-empty">
          <Compass size={32} />
          <h3>No templates found</h3>
          <p className="muted">Try adjusting your category or style archetype filters.</p>
          <button
            className="btn btn-outline mt-2"
            onClick={() => {
              setCategory("all");
              setArchetype("all");
              setQuery("");
            }}
          >
            Reset Filters
          </button>
        </div>
      )}

      {/* Interactive Responsive Preview Modal */}
      {previewItem && (
        <Modal
          title={`Preview: ${previewItem.name}`}
          onClose={() => setPreviewItem(null)}
          wide
        >
          <div className="ui-lib-preview-dialog">
            {/* Viewport bar */}
            <div className="ui-lib-preview-topbar">
              <div className="ui-lib-device-toggle">
                <button
                  className={`device-btn ${device === "desktop" ? "active" : ""}`}
                  onClick={() => setDevice("desktop")}
                  title="Desktop (100% full width)"
                >
                  <Monitor size={15} /> <span>Desktop</span>
                </button>
                <button
                  className={`device-btn ${device === "tablet" ? "active" : ""}`}
                  onClick={() => setDevice("tablet")}
                  title="Tablet (768px)"
                >
                  <Tablet size={15} /> <span>Tablet</span>
                </button>
                <button
                  className={`device-btn ${device === "mobile" ? "active" : ""}`}
                  onClick={() => setDevice("mobile")}
                  title="Mobile (375px)"
                >
                  <Smartphone size={15} /> <span>Mobile</span>
                </button>
              </div>

              <div className="ui-lib-preview-actions">
                <button
                  className="btn btn-sm btn-outline"
                  onClick={() => handleCopyJson(previewItem)}
                >
                  {copiedId === previewItem.id ? <Check size={14} /> : <Copy size={14} />}
                  <span>{copiedId === previewItem.id ? "Copied!" : "Copy JSON"}</span>
                </button>

                {previewItem.category === "theme" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => {
                      void handleApplyTheme(previewItem);
                      setPreviewItem(null);
                    }}
                  >
                    <Sparkles size={14} /> Apply Theme
                  </button>
                )}

                {previewItem.category === "page" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => {
                      void handleCreatePage(previewItem);
                      setPreviewItem(null);
                    }}
                  >
                    <FilePlus size={14} /> Create Page
                  </button>
                )}

                {(previewItem.category === "header" || previewItem.category === "footer") && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => {
                      void handleCreateTemplate(
                        previewItem,
                        previewItem.category as "header" | "footer",
                        true
                      );
                      setPreviewItem(null);
                    }}
                  >
                    <LayoutTemplate size={14} /> Set as Site {previewItem.category === "header" ? "Header" : "Footer"}
                  </button>
                )}

                {previewItem.category === "section" && (
                  <button
                    className="btn btn-sm btn-primary"
                    disabled={busy}
                    onClick={() => {
                      void handleCreateReusable(previewItem);
                      setPreviewItem(null);
                    }}
                  >
                    <Plus size={14} /> Add to Reusables
                  </button>
                )}
              </div>
            </div>

            {/* Preview Frame */}
            <div className="ui-lib-preview-stage">
              <div
                className={`ui-lib-viewport-container device-${device}`}
                style={themeToCssVars(previewItem.tokens) as React.CSSProperties}
              >
                <style>{themeToDesignRules(previewItem.tokens, ".ui-lib-viewport-container")}</style>

                {previewItem.category === "theme" ? (
                  <div className="ui-lib-theme-showcase site-root">
                    <div className="theme-showcase-hero" style={{ background: "var(--site-surface)" }}>
                      <span className="eyebrow" style={{ color: "var(--site-accent)" }}>
                        {previewItem.archetypeLabel}
                      </span>
                      <h1 style={{ color: "var(--site-ink)" }}>
                        {previewItem.name}
                      </h1>
                      <p style={{ color: "var(--site-ink)", opacity: 0.8 }}>
                        {previewItem.description}
                      </p>
                      <div className="flex-row gap-2 mt-3">
                        <button className="site-btn" style={{ background: "var(--site-primary)", color: "#fff" }}>
                          Primary Action
                        </button>
                        <button className="site-btn" style={{ background: "transparent", color: "var(--site-ink)", border: "1px solid var(--site-ink)" }}>
                          Outline Action
                        </button>
                      </div>
                    </div>

                    <div className="theme-token-grid">
                      <div className="token-card" style={{ background: "var(--site-surface)" }}>
                        <h4>Typography Scale</h4>
                        <div className="type-sample">
                          <span className="type-meta">Heading Font: {previewItem.tokens.headingFont || previewItem.tokens.font}</span>
                          <h2>Headline Two (Display)</h2>
                          <h3>Headline Three (Subheading)</h3>
                          <p>Body text rendered in {previewItem.tokens.bodyFont || previewItem.tokens.font}. Crisp rhythm, carefully balanced line height for high legibility.</p>
                        </div>
                      </div>

                      <div className="token-card" style={{ background: "var(--site-surface)" }}>
                        <h4>Color Palette</h4>
                        <div className="palette-rows">
                          <div className="palette-row">
                            <span className="color-swatch-box" style={{ background: previewItem.tokens.primary }} />
                            <span>Primary: <code>{previewItem.tokens.primary}</code></span>
                          </div>
                          {previewItem.tokens.accent && (
                            <div className="palette-row">
                              <span className="color-swatch-box" style={{ background: previewItem.tokens.accent }} />
                              <span>Accent: <code>{previewItem.tokens.accent}</code></span>
                            </div>
                          )}
                          <div className="palette-row">
                            <span className="color-swatch-box" style={{ background: previewItem.tokens.ink }} />
                            <span>Ink: <code>{previewItem.tokens.ink}</code></span>
                          </div>
                          <div className="palette-row">
                            <span className="color-swatch-box" style={{ background: previewItem.tokens.bg }} />
                            <span>Background: <code>{previewItem.tokens.bg}</code></span>
                          </div>
                          {previewItem.tokens.surface && (
                            <div className="palette-row">
                              <span className="color-swatch-box" style={{ background: previewItem.tokens.surface }} />
                              <span>Surface: <code>{previewItem.tokens.surface}</code></span>
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="ui-lib-rendered-layout site-root">
                    {previewItem.layout && (
                      <LayoutRenderer layout={previewItem.layout} mode="public" />
                    )}
                    {!previewItem.layout && previewItem.block && (
                      <LayoutRenderer
                        layout={{ version: 1, blocks: [previewItem.block] }}
                        mode="public"
                      />
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
