import { useState } from "react";
import { Settings2, Trash2, CopyPlus } from "lucide-react";
import { registry } from "@/blocks/registry";
import type { Block } from "@/lib/schema/layout";
import type { Breakpoint } from "@/lib/schema/style";
import type { ReusableLibrary } from "@/lib/editor/useReusables";
import {
  buildStylePatch,
  hasOverrides,
  styleForBreakpoint,
  type FieldKey,
} from "@/lib/editor/styleModel";
import { FieldsForm } from "./FieldsForm";
import { ReusablePanel, SaveAsReusable } from "./ReusablePanel";
import { StylePanel } from "./style/StylePanel";
import { AdvancedPanel } from "./style/AdvancedPanel";
import { DeviceSwitcher } from "./style/DeviceSwitcher";

type Tab = "content" | "style" | "advanced";

/**
 * The block inspector — the right-hand settings surface.
 *
 * Split into three tabs the way a mature visual editor is:
 *   • Content   — the block's own fields (from its registry definition)
 *   • Style     — presentation, scoped to the device you are editing
 *   • Advanced  — identity, CSS hook, responsive reset
 *
 * The component holds only the tab + device choice. Every edit is reported upward through
 * `onPatch` / `onPatchAdvanced`, so the inspector has no opinion about the document shape.
 */
export function Inspector({
  block,
  errors,
  onPatch,
  onPatchAdvanced,
  onDelete,
  onDuplicate,
  library,
  onSaveAsReusable,
  onDetach,
}: {
  block?: Block;
  errors: Record<string, string>;
  onPatch: (patch: Record<string, unknown>) => void;
  onPatchAdvanced: (patch: Record<string, unknown>) => void;
  onDelete: () => void;
  onDuplicate: () => void;
  library: ReusableLibrary;
  onSaveAsReusable: (block: Block, name: string) => Promise<void>;
  onDetach: (block: Block) => void;
}) {
  const [tab, setTab] = useState<Tab>("content");
  const [device, setDevice] = useState<Breakpoint>("base");

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
  const advanced = block.advanced;
  const effective = styleForBreakpoint(advanced, device);

  // Every style edit goes through buildStylePatch so the panel never sees the breakpoint nesting.
  const patchStyle = (field: FieldKey, value: unknown) =>
    onPatchAdvanced(buildStylePatch(field, value, device));

  return (
    <div className="inspector-content">
      <div className="inspector-title">
        <div>
          <span className="eyebrow">selected / {block.type}</span>
          <h2>{advanced?.name || def.label}</h2>
        </div>
        <div className="inspector-title-actions">
          <button
            className="icon-btn"
            onClick={onDuplicate}
            aria-label={`Duplicate ${def.label} block`}
            title="Duplicate"
          >
            <CopyPlus size={15} aria-hidden="true" />
          </button>
          <button
            className="icon-btn danger"
            onClick={onDelete}
            aria-label={`Delete ${def.label} block`}
            title="Delete"
          >
            <Trash2 size={15} aria-hidden="true" />
          </button>
        </div>
      </div>

      <div className="sx-tabs" role="tablist" aria-label="Block settings">
        {(
          [
            ["content", "Content"],
            ["style", "Style"],
            ["advanced", "Advanced"],
          ] as const
        ).map(([id, label]) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            className={tab === id ? "active" : ""}
            onClick={() => setTab(id)}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "content" && (
        <div role="tabpanel" aria-label="Content">
          {(block.type === "services" || block.type === "portfolio") && (
            <p className="readonly-note">
              Items come live from WordPress. This block stores only the source
              and display rules.
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
        </div>
      )}

      {tab === "style" && (
        <div role="tabpanel" aria-label="Style">
          <DeviceSwitcher
            value={device}
            onChange={setDevice}
            overridden={{
              tablet: Boolean(advanced?.overrides?.tablet),
              phone: Boolean(advanced?.overrides?.phone),
            }}
          />
          <StylePanel
            style={effective}
            breakpoint={device}
            onPatch={patchStyle}
          />
        </div>
      )}

      {tab === "advanced" && (
        <div role="tabpanel" aria-label="Advanced">
          <AdvancedPanel
            style={advanced}
            hasResponsive={hasOverrides(advanced)}
            onPatch={onPatchAdvanced}
            onResetResponsive={() => onPatchAdvanced({ overrides: undefined })}
          />
        </div>
      )}

      <div className="inspector-foot">
        <span className="eyebrow">block id</span>
        <code>{block.id}</code>
        {advanced?.cssClass && (
          <>
            <span className="eyebrow">class</span>
            <code>.{advanced.cssClass}</code>
          </>
        )}
      </div>
    </div>
  );
}

