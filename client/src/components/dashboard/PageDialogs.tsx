import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { PageRow } from "@/lib/schema/api";
import { Modal } from "../Modal";
import { ImageField } from "./ImageField";

const slugify = (s: string) =>
  s
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 190);

function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <div className="field check">
      <label>
        <input
          type="checkbox"
          checked={checked}
          onChange={e => onChange(e.target.checked)}
        />{" "}
        <span>{label}</span>
      </label>
    </div>
  );
}

export function NewPageDialog({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (p: PageRow) => void;
}) {
  const [title, setTitle] = useState("");
  const [slug, setSlug] = useState("");
  const [edited, setEdited] = useState(false);
  const [starter, setStarter] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const submit = () => {
    setBusy(true);
    setError("");
    api
      .createPage(title.trim(), slug.trim(), starter)
      .then(onCreated)
      .catch(e => {
        setError(describeError(e));
        setBusy(false);
      });
  };
  return (
    <Modal title="New page" onClose={onClose} dismissable={!busy}>
      <div className="field">
        <label htmlFor="np-title">
          <span>Page title</span>
        </label>
        <input
          id="np-title"
          data-autofocus
          type="text"
          maxLength={200}
          value={title}
          onChange={e => {
            setTitle(e.target.value);
            if (!edited) setSlug(slugify(e.target.value));
          }}
          onKeyDown={e => e.key === "Enter" && title.trim() && submit()}
        />
      </div>
      <div className="field">
        <label htmlFor="np-slug">
          <span>Address</span>
        </label>
        <input
          id="np-slug"
          type="text"
          value={slug}
          onChange={e => {
            setEdited(true);
            setSlug(slugify(e.target.value));
          }}
        />
        <small className="muted">/{slug || "…"}</small>
      </div>
      <Toggle
        label="Start with the site header and footer"
        checked={starter}
        onChange={setStarter}
      />
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" onClick={onClose} disabled={busy}>
          Cancel
        </button>
        <button
          className="save-btn"
          onClick={submit}
          disabled={busy || title.trim() === ""}
        >
          {busy ? "Creating…" : "Create and edit"}
        </button>
      </div>
    </Modal>
  );
}

export function RenameDialog({
  page,
  onClose,
  onSaved,
}: {
  page: PageRow;
  onClose: () => void;
  onSaved: (p: PageRow) => void;
}) {
  const [title, setTitle] = useState(page.title);
  const [slug, setSlug] = useState(page.slug);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const live = page.status === "publish";
  const changedSlug = slug !== page.slug;

  const submit = () => {
    setBusy(true);
    setError("");
    api
      .updatePageMeta(page.id, {
        ...(title !== page.title ? { title: title.trim() } : {}),
        ...(changedSlug ? { slug } : {}),
      })
      .then(onSaved)
      .catch(e => {
        setError(describeError(e));
        setBusy(false);
      });
  };
  return (
    <Modal title="Rename page" onClose={onClose} dismissable={!busy}>
      <div className="field">
        <label htmlFor="rn-title">
          <span>Page title</span>
        </label>
        <input
          id="rn-title"
          data-autofocus
          type="text"
          maxLength={200}
          value={title}
          onChange={e => setTitle(e.target.value)}
        />
      </div>
      <div className="field">
        <label htmlFor="rn-slug">
          <span>Address</span>
        </label>
        <input
          id="rn-slug"
          type="text"
          value={slug}
          onChange={e => setSlug(slugify(e.target.value))}
        />
      </div>
      {live && changedSlug && (
        <p className="notice warn inline" role="status">
          This page is live. Changing its address breaks links to the old one.
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" onClick={onClose} disabled={busy}>
          Cancel
        </button>
        <button
          className="save-btn"
          onClick={submit}
          disabled={
            busy ||
            title.trim() === "" ||
            slug === "" ||
            (title === page.title && !changedSlug)
          }
        >
          Save
        </button>
      </div>
    </Modal>
  );
}

export function SeoDialog({
  page,
  siteName,
  onClose,
  onSaved,
}: {
  page: PageRow;
  siteName: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [f, setF] = useState<null | {
    title: string;
    description: string;
    image: string;
    noindex: boolean;
  }>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    api
      .getPageSeo(page.id)
      .then(s =>
        setF({
          title: s.title,
          description: s.description,
          image: s.image,
          noindex: s.noindex,
        })
      )
      .catch(e => setError(describeError(e)));
  }, [page.id]);

  const save = () => {
    if (!f) return;
    setBusy(true);
    setError("");
    api
      .setPageSeo(page.id, f)
      .then(() => onSaved())
      .catch(e => {
        setError(describeError(e));
        setBusy(false);
      });
  };
  const shownTitle = f?.title.trim() || page.title;
  return (
    <Modal
      title={`Search & sharing: ${page.title}`}
      onClose={onClose}
      wide
      dismissable={!busy}
    >
      {!f && !error && <p className="muted">Loading…</p>}
      {f && (
        <>
          <div className="serp" aria-label="Search result preview">
            <span className="serp-url">{page.link ?? `/${page.slug}`}</span>
            <strong className="serp-title">
              {shownTitle} | {siteName}
            </strong>
            <span className="serp-desc">
              {f.description.trim() ||
                "No description yet. Search engines will pick a snippet from the page."}
            </span>
          </div>
          <div className="social-card" aria-label="Social share preview">
            {f.image ? (
              <img src={f.image} alt="" />
            ) : (
              <div className="social-card-empty">Site default image</div>
            )}
            <div className="social-card-body">
              <small>
                {(page.link ?? "").replace(/^https?:\/\//, "").split("/")[0]}
              </small>
              <strong>{shownTitle}</strong>
              <span>{f.description.trim() || ""}</span>
            </div>
          </div>
          <div className="field">
            <label htmlFor="seo-title">
              <span>Search title</span>
            </label>
            <input
              id="seo-title"
              data-autofocus
              type="text"
              maxLength={200}
              value={f.title}
              placeholder={page.title}
              onChange={e => setF({ ...f, title: e.target.value })}
            />
            <small className="muted">{f.title.length}/60 recommended</small>
          </div>
          <div className="field">
            <label htmlFor="seo-desc">
              <span>Search description</span>
            </label>
            <textarea
              id="seo-desc"
              rows={3}
              maxLength={400}
              value={f.description}
              onChange={e => setF({ ...f, description: e.target.value })}
            />
            <small className="muted">
              {f.description.length}/160 recommended
            </small>
          </div>
          <ImageField
            id="seo-img"
            label="Social sharing image"
            value={f.image}
            onChange={image => setF({ ...f, image })}
            help="Shown when this page is shared on Facebook, LinkedIn, WhatsApp and similar. 1200 × 630 px works best. Leave empty to use the site default."
          />
          <Toggle
            label="Hide this page from search engines (noindex)"
            checked={f.noindex}
            onChange={v => setF({ ...f, noindex: v })}
          />
        </>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="dialog-actions">
        <button className="top-btn" onClick={onClose} disabled={busy}>
          Cancel
        </button>
        <button className="save-btn" onClick={save} disabled={busy || !f}>
          Save
        </button>
      </div>
    </Modal>
  );
}
