import { Settings2, Trash2 } from "lucide-react";
import { registry } from "@/blocks/registry";
import type { Block } from "@/lib/schema/layout";
import type { ReusableLibrary } from "@/lib/editor/useReusables";
import { FieldsForm } from "./FieldsForm";
import { ReusablePanel, SaveAsReusable } from "./ReusablePanel";

export function Inspector({
  block,
  errors,
  onPatch,
  onDelete,
  library,
  onSaveAsReusable,
  onDetach,
}: {
  block?: Block;
  errors: Record<string, string>;
  onPatch: (patch: Record<string, unknown>) => void;
  onDelete: () => void;
  library: ReusableLibrary;
  onSaveAsReusable: (block: Block, name: string) => Promise<void>;
  onDetach: (block: Block) => void;
}) {
  if (!block) {
    return (
      <div className="inspector-empty">
        <Settings2 size={20} aria-hidden="true" />
        <strong>Select a block</strong>
        <p>
          Choose a section on the canvas to edit its content and configuration.
        </p>
      </div>
    );
  }
  const def = registry[block.type];
  return (
    <div className="inspector-content">
      <div className="inspector-title">
        <div>
          <span className="eyebrow">selected / {block.type}</span>
          <h2>{def.label}</h2>
        </div>
        <button
          className="icon-btn danger"
          onClick={onDelete}
          aria-label={`Delete ${def.label} block`}
        >
          <Trash2 size={15} aria-hidden="true" />
        </button>
      </div>
      {(block.type === "services" || block.type === "portfolio") && (
        <p className="readonly-note">
          Items come live from WordPress. This block stores only the source and
          display rules.
        </p>
      )}
      {block.type === "reusable" ? (
        (() => {
          const record = library.source.get(block.props.refId);
          return record ? (
            <ReusablePanel
              key={`${block.id}:${record.id}`}
              record={record}
              library={library}
              onDetach={() => onDetach(block)}
            />
          ) : (
            <p className="readonly-note" role="note">
              {library.status === "loading"
                ? "Loading the library…"
                : "This reusable block was deleted or could not be loaded. Remove it from the page."}
            </p>
          );
        })()
      ) : (
        <>
          <FieldsForm
            key={block.id}
            fields={def.fields}
            values={block.props as Record<string, unknown>}
            errors={errors}
            onChange={onPatch}
          />
          <SaveAsReusable
            key={`save:${block.id}`}
            onSave={name => onSaveAsReusable(block, name)}
          />
        </>
      )}
      <div className="inspector-foot">
        <span className="eyebrow">block id</span>
        <code>{block.id}</code>
      </div>
    </div>
  );
}
