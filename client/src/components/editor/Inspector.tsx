import { Settings2, Trash2 } from "lucide-react";
import { registry } from "@/blocks/registry";
import type { Block } from "@/lib/schema/layout";
import { FieldsForm } from "./FieldsForm";

export function Inspector({
  block,
  errors,
  onPatch,
  onDelete,
}: {
  block?: Block;
  errors: Record<string, string>;
  onPatch: (patch: Record<string, unknown>) => void;
  onDelete: () => void;
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
      <FieldsForm
        key={block.id}
        fields={def.fields}
        values={block.props as Record<string, unknown>}
        errors={errors}
        onChange={onPatch}
      />
      <div className="inspector-foot">
        <span className="eyebrow">block id</span>
        <code>{block.id}</code>
      </div>
    </div>
  );
}
