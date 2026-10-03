import { useCallback, useEffect, useState } from "react";
import { Layers3, Pencil, Trash2 } from "lucide-react";
import { registry } from "@/blocks/registry";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { ReusableItem } from "@/lib/schema/api";
import { BulkBar, RowCheck, runEach, useSelection } from "./Bulk";

const kindLabel = (t: string) =>
  (registry as Record<string, { label: string }>)[t]?.label ?? t;

/** The saved blocks that pages share. They are made in the editor ("Save as reusable"); here they are renamed and cleaned up. */
export function ReusableLibrary() {
  const [items, setItems] = useState<ReusableItem[] | null>(null);
  const [editing, setEditing] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const pick = useSelection((items ?? []).map(i => i.id));

  const load = useCallback(
    () =>
      api
        .listReusables(true)
        .then(setItems)
        .catch(e => setError(describeError(e))),
    []
  );
  useEffect(() => {
    void load();
  }, [load]);

  if (!items) return error ? <p className="form-error">{error}</p> : null;

  const used = (i: ReusableItem) => (i.uses ?? 0) > 0;
  const rename = (i: ReusableItem) => {
    const next = name.trim();
    if (!next || next === i.name) {
      setEditing(null);
      return;
    }
    setBusy(true);
    setError("");
    api
      .updateReusable(i.id, { name: next })
      .then(() => {
        setNote(`Renamed to “${next}”.`);
        setEditing(null);
        return load();
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };
  const removeMany = (list: ReusableItem[]) => {
    const free = list.filter(i => !used(i));
    if (free.length === 0) {
      setNote("");
      setError(
        "Those blocks are still used on pages. Detach or remove them there first."
      );
      return;
    }
    const skipped = list.length - free.length;
    if (
      !window.confirm(
        `Delete ${free.length} reusable block${free.length === 1 ? "" : "s"} for good?${skipped ? ` ${skipped} that pages still use will be kept.` : ""} This cannot be undone.`
      )
    )
      return;
    setBusy(true);
    setNote("");
    setError("");
    runEach(free, i => api.deleteReusable(i.id), "Deleted", "reusable block")
      .then(r => {
        setNote(r.note + (skipped ? ` Kept ${skipped} still in use.` : ""));
        setError(r.error);
        pick.clear();
        return load();
      })
      .finally(() => setBusy(false));
  };

  return (
    <section className="dash-card" aria-labelledby="rl-h">
      <h2 id="rl-h">Reusable blocks</h2>
      <p className="muted">
        Blocks saved once and shared by many pages, such as a header, footer or
        call-to-action. Editing one changes it everywhere. Rename the ones you
        keep, and delete the ones no page uses.
      </p>
      {note && (
        <p className="notice info inline" role="status">
          {note}
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {items.length === 0 ? (
        <p className="muted">
          None yet. Select a block in the editor and choose “Save as reusable”.
        </p>
      ) : (
        <>
          <BulkBar
            noun="reusable blocks"
            count={pick.count}
            total={items.length}
            all={pick.all}
            onToggleAll={pick.toggleAll}
            onClear={pick.clear}
            busy={busy}
            actions={[
              {
                label: "Delete",
                icon: <Trash2 size={14} aria-hidden="true" />,
                danger: true,
                onClick: () => removeMany(items.filter(i => pick.has(i.id))),
              },
            ]}
          />
          <ul className="dash-pages">
            {items.map(i => (
              <li
                key={i.id}
                className={`selectable${busy ? " busy" : ""}${pick.has(i.id) ? " picked" : ""}`}
              >
                <RowCheck
                  checked={pick.has(i.id)}
                  onChange={() => pick.toggle(i.id)}
                  label={`Select ${i.name}`}
                />
                <Layers3 size={18} aria-hidden="true" className="tpl-icon" />
                <div className="dash-page-main">
                  {editing === i.id ? (
                    <input
                      aria-label="Name"
                      value={name}
                      maxLength={80}
                      autoFocus
                      onChange={e => setName(e.target.value)}
                      onKeyDown={e => {
                        if (e.key === "Enter") rename(i);
                        if (e.key === "Escape") setEditing(null);
                      }}
                      onBlur={() => rename(i)}
                    />
                  ) : (
                    <strong className="dash-page-title">{i.name}</strong>
                  )}
                  <div className="dash-page-meta">
                    <span>{kindLabel(i.block.type)}</span>
                    <span className={`badge ${used(i) ? "publish" : "draft"}`}>
                      {used(i)
                        ? `used on ${i.uses} page${i.uses === 1 ? "" : "s"}`
                        : "not used"}
                    </span>
                  </div>
                </div>
                <div className="dash-page-actions">
                  <button
                    className="top-btn"
                    onClick={() => {
                      setName(i.name);
                      setEditing(i.id);
                    }}
                  >
                    <Pencil size={13} aria-hidden="true" /> Rename
                  </button>
                  <button
                    className="icon-btn"
                    aria-label={`Delete ${i.name}`}
                    title={
                      used(i)
                        ? "Still used on pages: detach it there first"
                        : "Delete"
                    }
                    disabled={used(i) || busy}
                    onClick={() => removeMany([i])}
                  >
                    <Trash2 size={14} aria-hidden="true" />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
