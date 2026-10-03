import { useCallback, useEffect, useState, type ReactNode } from "react";
import { describeError } from "@/lib/api/errors";

/** Which rows of a list are ticked. Keys that leave the list are dropped, so a reload never keeps a stale pick. */
export function useSelection<K extends string | number>(visible: K[]) {
  const [sel, setSel] = useState<Set<K>>(() => new Set());
  const key = visible.join("\u0000");

  useEffect(() => {
    setSel(prev => {
      const keep = new Set(visible);
      const next = new Set([...prev].filter(k => keep.has(k)));
      return next.size === prev.size ? prev : next;
    });
    // `key` stands for the contents of `visible`
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  const toggle = useCallback(
    (k: K) =>
      setSel(prev => {
        const next = new Set(prev);
        if (next.has(k)) next.delete(k);
        else next.add(k);
        return next;
      }),
    []
  );
  const all = visible.length > 0 && visible.every(k => sel.has(k));
  return {
    has: (k: K) => sel.has(k),
    ids: visible.filter(k => sel.has(k)),
    count: visible.filter(k => sel.has(k)).length,
    all,
    toggle,
    toggleAll: () => setSel(all ? new Set() : new Set(visible)),
    clear: () => setSel(new Set()),
  };
}

/** The tick box at the start of a row. */
export function RowCheck({
  checked,
  onChange,
  label,
  disabled,
}: {
  checked: boolean;
  onChange: () => void;
  label: string;
  disabled?: boolean;
}) {
  return (
    <input
      type="checkbox"
      className="row-check"
      checked={checked}
      disabled={disabled}
      aria-label={label}
      onChange={onChange}
    />
  );
}

export interface BulkAction {
  label: string;
  icon?: ReactNode;
  danger?: boolean;
  disabled?: boolean;
  onClick: () => void;
}

/** "Select all" on the left; once something is ticked, the count and the actions for those rows. */
export function BulkBar({
  noun,
  count,
  total,
  all,
  onToggleAll,
  onClear,
  actions,
  busy,
}: {
  noun: string;
  count: number;
  total: number;
  all: boolean;
  onToggleAll: () => void;
  onClear: () => void;
  actions: BulkAction[];
  busy?: boolean;
}) {
  if (total === 0) return null;
  return (
    <div
      className={`bulk-bar${count > 0 ? " on" : ""}`}
      role="region"
      aria-label="Bulk actions"
    >
      <label className="bulk-all">
        <input
          type="checkbox"
          className="row-check"
          checked={all}
          ref={el => {
            if (el) el.indeterminate = count > 0 && !all;
          }}
          onChange={onToggleAll}
        />
        <span className={count > 0 ? "bulk-count" : "muted"}>
          {count > 0 ? `${count} selected` : `Select all ${total} ${noun}`}
        </span>
      </label>
      {count > 0 && (
        <div className="bulk-actions">
          {actions.map(a => (
            <button
              key={a.label}
              className={`top-btn${a.danger ? " danger" : ""}`}
              disabled={busy || a.disabled}
              onClick={a.onClick}
            >
              {a.icon} {a.label}
            </button>
          ))}
          <button className="top-btn" disabled={busy} onClick={onClear}>
            Clear
          </button>
        </div>
      )}
    </div>
  );
}

/** Runs one request per item and tells the user how many worked, in plain words. */
export async function runEach<T>(
  items: T[],
  fn: (item: T) => Promise<unknown>,
  verb: string,
  noun: string
): Promise<{ note: string; error: string }> {
  const results = await Promise.allSettled(items.map(fn));
  const failed = results.filter(r => r.status === "rejected");
  const ok = results.length - failed.length;
  const first = failed[0] as PromiseRejectedResult | undefined;
  return {
    note: ok > 0 ? `${verb} ${ok} ${noun}${ok === 1 ? "" : "s"}.` : "",
    error: first
      ? `${failed.length} could not be done: ${describeError(first.reason)}`
      : "",
  };
}
