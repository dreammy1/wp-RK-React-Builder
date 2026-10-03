import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeError } from "@/lib/api/errors";
import type { RedirectRule } from "@/lib/schema/api";
import { BulkBar, RowCheck, useSelection } from "./Bulk";

export function RedirectsSection() {
  const [items, setItems] = useState<RedirectRule[] | null>(null);
  const [draft, setDraft] = useState<RedirectRule>({
    from: "",
    to: "",
    code: 301,
  });
  const [bulk, setBulk] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const pick = useSelection((items ?? []).map(r => r.from));

  useEffect(() => {
    api
      .getRedirects()
      .then(r => setItems(r.items))
      .catch(e => setError(describeError(e)));
  }, []);

  const persist = (next: RedirectRule[], msg: string) => {
    setBusy(true);
    setError("");
    setNote("");
    api
      .setRedirects(next)
      .then(r => {
        setItems(r.items);
        setNote(msg);
        setDraft({ from: "", to: "", code: 301 });
        setBulk("");
        pick.clear();
      })
      .catch(e => setError(describeError(e)))
      .finally(() => setBusy(false));
  };

  if (!items)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const addBulk = () => {
    const rules = bulk
      .split("\n")
      .map(l => l.trim())
      .filter(Boolean)
      .map(l => {
        const [from = "", to = ""] = l.split(/\s*(?:->|=>|,|\t)\s*/);
        return { from, to, code: 301 };
      });
    persist(
      [...items, ...rules],
      `Added ${rules.length} redirect${rules.length === 1 ? "" : "s"}.`
    );
  };

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>Redirects</h1>
          <p className="muted">
            Send visitors from an old address to a new one, so links and search
            results keep working after you rename or remove a page.
          </p>
        </div>
      </header>
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

      <section className="dash-card">
        <h2>Add a redirect</h2>
        <div className="redirect-row">
          <input
            aria-label="Old path"
            placeholder="/old-page"
            value={draft.from}
            onChange={e => setDraft({ ...draft, from: e.target.value })}
          />
          <input
            aria-label="New path or address"
            placeholder="/new-page or https://…"
            value={draft.to}
            onChange={e => setDraft({ ...draft, to: e.target.value })}
          />
          <select
            aria-label="Type"
            value={draft.code}
            onChange={e => setDraft({ ...draft, code: Number(e.target.value) })}
          >
            <option value={301}>301 permanent</option>
            <option value={302}>302 temporary</option>
          </select>
          <button
            className="save-btn"
            disabled={busy || !draft.from || !draft.to}
            onClick={() => persist([...items, draft], "Redirect added.")}
          >
            Add
          </button>
        </div>
        <details>
          <summary>Add many at once</summary>
          <div className="field">
            <label htmlFor="rd-bulk">
              <span>One per line: old path -&gt; new path</span>
            </label>
            <textarea
              id="rd-bulk"
              className="code-area"
              value={bulk}
              placeholder={
                "/old-services -> /services\n/promo -> https://example.com/offer"
              }
              onChange={e => setBulk(e.target.value)}
            />
          </div>
          <button
            className="top-btn"
            disabled={busy || !bulk.trim()}
            onClick={addBulk}
          >
            Add these
          </button>
        </details>
      </section>

      <section className="dash-card">
        <h2>Active redirects ({items.length})</h2>
        {items.length === 0 ? (
          <p className="muted">No redirects yet.</p>
        ) : (
          <>
            <BulkBar
              noun="redirects"
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
                  onClick: () => {
                    if (
                      window.confirm(
                        `Delete ${pick.count} redirect${pick.count === 1 ? "" : "s"}?`
                      )
                    )
                      persist(
                        items.filter(x => !pick.has(x.from)),
                        `Removed ${pick.count} redirect${pick.count === 1 ? "" : "s"}.`
                      );
                  },
                },
              ]}
            />
            <ul className="dash-list">
              {items.map(r => (
                <li
                  key={r.from}
                  className={`selectable${pick.has(r.from) ? " picked" : ""}`}
                >
                  <RowCheck
                    checked={pick.has(r.from)}
                    onChange={() => pick.toggle(r.from)}
                    label={`Select redirect from ${r.from}`}
                  />
                  <code>{r.from}</code>
                  <span aria-hidden="true">→</span>
                  <code>{r.to}</code>
                  <small className="muted">{r.code}</small>
                  <button
                    className="icon-btn danger"
                    aria-label={`Delete redirect from ${r.from}`}
                    onClick={() =>
                      persist(
                        items.filter(x => x.from !== r.from),
                        "Redirect removed."
                      )
                    }
                  >
                    <Trash2 size={14} aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </>
  );
}
