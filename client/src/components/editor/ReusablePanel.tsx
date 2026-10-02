import { useState } from "react";
import { Link2Off, Save } from "lucide-react";
import { registry } from "@/blocks/registry";
import { describeError } from "@/lib/api/errors";
import type { ReusableLibrary } from "@/lib/editor/useReusables";
import type { ReusableItem } from "@/lib/schema/api";
import { friendlyMessage } from "@/lib/schema/messages";
import { FieldsForm } from "./FieldsForm";

/** Inspector for a block that points at the library: edit the shared content, rename it, or detach. */
export function ReusablePanel({
  record,
  library,
  onDetach,
}: {
  record: ReusableItem;
  library: ReusableLibrary;
  onDetach: () => void;
}) {
  const def = registry[record.block.type];
  const [name, setName] = useState(record.name);
  const [props, setProps] = useState<Record<string, unknown>>(() =>
    structuredClone(record.block.props as Record<string, unknown>)
  );
  const [state, setState] = useState<{
    busy: boolean;
    msg?: string;
    error?: string;
  }>({ busy: false });

  const errors: Record<string, string> = {};
  const parsed = def.schema.safeParse(props);
  if (!parsed.success)
    for (const i of parsed.error.issues)
      errors[String(i.path[0] ?? "")] ||= friendlyMessage(i);
  const dirty =
    name !== record.name ||
    JSON.stringify(props) !== JSON.stringify(record.block.props);
  const nameOk = name.trim().length >= 1 && name.trim().length <= 80;

  return (
    <div className="reusable-panel">
      <p className="readonly-note">
        Shared block. Changes here update <strong>every page</strong> that uses
        it, once you click <em>Update everywhere</em>.
      </p>
      <label className="field">
        <span>Library name</span>
        <input
          type="text"
          value={name}
          maxLength={80}
          onChange={e => setName(e.target.value)}
        />
      </label>
      <FieldsForm
        key={record.id}
        fields={def.fields}
        values={props}
        errors={errors}
        onChange={patch => setProps(p => ({ ...p, ...patch }))}
      />
      {state.error && (
        <p className="form-error" role="alert">
          {state.error}
        </p>
      )}
      {state.msg && (
        <p className="muted" role="status">
          {state.msg}
        </p>
      )}
      <div className="dialog-actions stack">
        <button
          className="save-btn"
          disabled={!dirty || !parsed.success || !nameOk || state.busy}
          onClick={() => {
            setState({ busy: true });
            library
              .update(record.id, {
                name: name.trim(),
                block: { type: record.block.type, props },
              })
              .then(() => setState({ busy: false, msg: "Updated everywhere." }))
              .catch(e => setState({ busy: false, error: describeError(e) }));
          }}
        >
          <Save size={14} aria-hidden="true" /> Update everywhere
        </button>
        <button className="top-btn" onClick={onDetach}>
          <Link2Off size={14} aria-hidden="true" /> Detach (make this
          page&apos;s own copy)
        </button>
      </div>
    </div>
  );
}

/** "Save as reusable": name it, store it in the library, and replace this block with a linked reference. */
export function SaveAsReusable({
  onSave,
}: {
  onSave: (name: string) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [state, setState] = useState<{ busy: boolean; error?: string }>({
    busy: false,
  });
  if (!open)
    return (
      <button
        className="top-btn save-reusable-btn"
        onClick={() => setOpen(true)}
      >
        Save as reusable block
      </button>
    );
  const ok = name.trim().length >= 1 && name.trim().length <= 80;
  return (
    <form
      className="save-reusable"
      onSubmit={e => {
        e.preventDefault();
        if (!ok) return;
        setState({ busy: true });
        onSave(name.trim()).catch(err =>
          setState({ busy: false, error: describeError(err) })
        );
      }}
    >
      <label className="field">
        <span>Name in the library</span>
        <input
          data-autofocus
          type="text"
          value={name}
          maxLength={80}
          placeholder="e.g. Footer call-to-action"
          onChange={e => setName(e.target.value)}
        />
      </label>
      <p className="muted">
        This block moves to the library and stays linked: edit it once, every
        page updates.
      </p>
      {state.error && (
        <p className="form-error" role="alert">
          {state.error}
        </p>
      )}
      <div className="dialog-actions">
        <button className="save-btn" type="submit" disabled={!ok || state.busy}>
          {state.busy ? "Saving…" : "Save to library"}
        </button>
        <button
          type="button"
          className="top-btn"
          onClick={() => setOpen(false)}
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
