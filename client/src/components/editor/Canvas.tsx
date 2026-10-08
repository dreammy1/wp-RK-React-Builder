import { useState } from "react";
import { ArrowDown, ArrowUp, Copy, GripVertical, Trash2 } from "lucide-react";
import { registry } from "@/blocks/registry";
import type { Block, LayoutDocument } from "@/lib/schema/layout";
import type { BlockType } from "@/lib/schema/primitives";
import { layoutStyles, styleClass } from "@/lib/schema/style";
import { BlockRenderer } from "@/render/BlockRenderer";

type Props = {
  /** Set when a header template is being edited: the live header is never transparent, so the canvas shows it solid. */
  solidHeader?: boolean;
  layout: LayoutDocument;
  selectedId: string | null;
  errors: Map<string, Record<string, string>>;
  dragType: BlockType | null;
  onDragEnd: () => void;
  onSelect: (id: string) => void;
  onAdd: (type: BlockType, index: number) => void;
  onMove: (id: string, toIndex: number) => void;
  onMoveBy: (id: string, delta: number) => void;
  onDuplicate: (id: string) => void;
  onRemove: (id: string) => void;
};

export function Canvas(p: Props) {
  const [dragIndex, setDragIndex] = useState<number | null>(null);
  const [dropIndex, setDropIndex] = useState<number | null>(null);
  const { blocks } = p.layout;

  const drop = (index: number) => {
    if (p.dragType) p.onAdd(p.dragType, index);
    else if (dragIndex !== null)
      p.onMove(blocks[dragIndex]!.id, dragIndex < index ? index - 1 : index);
    setDragIndex(null);
    setDropIndex(null);
    p.onDragEnd();
  };
  const zone = (index: number, end = false) => (
    <div
      className={`drop-zone ${end ? "end" : ""} ${dropIndex === index ? "visible" : ""}`}
      onDragOver={e => {
        e.preventDefault();
        setDropIndex(index);
      }}
      onDragLeave={() => setDropIndex(d => (d === index ? null : d))}
      onDrop={e => {
        e.preventDefault();
        drop(index);
      }}
      aria-hidden="true"
    />
  );

  return (
    <div
      className={`site-root site-canvas editor-canvas${p.solidHeader ? " solid-header" : ""}`}
    >
      {/* Advanced styles for the canvas, scoped to each block's style class. */}
      {(() => {
        const css = layoutStyles(blocks, "editor");
        return css ? <style>{css}</style> : null;
      })()}
      {blocks.length === 0 && (
        <div className="empty-canvas">
          <span aria-hidden="true">+</span>
          <strong>This page has no blocks yet</strong>
          <p>Choose a block from the palette, or drag one onto this canvas.</p>
        </div>
      )}
      {blocks.map((block: Block, index) => {
        const def = registry[block.type];
        const invalid = p.errors.has(block.id);
        const label = `${def.label}, block ${index + 1} of ${blocks.length}`;
        return (
          <div key={block.id}>
            {zone(index)}
            <div
              role="presentation" /* mouse convenience only; keyboard selection uses the handle button */
              className={`canvas-block ${styleClass(block.id)} ${p.selectedId === block.id ? "selected" : ""} ${invalid ? "invalid" : ""}`}
              draggable
              onDragStart={e => {
                e.dataTransfer.effectAllowed = "move";
                setDragIndex(index);
              }}
              onDragEnd={() => {
                setDragIndex(null);
                setDropIndex(null);
              }}
              onClick={() => p.onSelect(block.id)}
              data-testid={`block-${block.type}`}
            >
              <div className="block-handle" role="group" aria-label={label}>
                <GripVertical size={14} aria-hidden="true" />
                <button
                  className="handle-label"
                  onClick={e => {
                    e.stopPropagation();
                    p.onSelect(block.id);
                  }}
                  aria-pressed={p.selectedId === block.id}
                  aria-label={`Select ${label}`}
                >
                  {String(index + 1).padStart(2, "0")} / {def.label}
                  {invalid ? " ⚠" : ""}
                </button>
                <div>
                  <button
                    disabled={index === 0}
                    onClick={e => {
                      e.stopPropagation();
                      p.onMoveBy(block.id, -1);
                    }}
                    aria-label={`Move ${def.label} up`}
                  >
                    <ArrowUp size={13} aria-hidden="true" />
                  </button>
                  <button
                    disabled={index === blocks.length - 1}
                    onClick={e => {
                      e.stopPropagation();
                      p.onMoveBy(block.id, 1);
                    }}
                    aria-label={`Move ${def.label} down`}
                  >
                    <ArrowDown size={13} aria-hidden="true" />
                  </button>
                  <button
                    onClick={e => {
                      e.stopPropagation();
                      p.onDuplicate(block.id);
                    }}
                    aria-label={`Duplicate ${def.label}`}
                  >
                    <Copy size={13} aria-hidden="true" />
                  </button>
                  <button
                    className="danger"
                    onClick={e => {
                      e.stopPropagation();
                      p.onRemove(block.id);
                    }}
                    aria-label={`Delete ${def.label}`}
                  >
                    <Trash2 size={13} aria-hidden="true" />
                  </button>
                </div>
              </div>
              {/* inert: links/buttons inside the live preview must not steal focus or navigate while editing */}
              <div
                className="canvas-view"
                inert
                onClickCapture={e => e.preventDefault()}
              >
                <BlockRenderer block={block} mode="editor" />
              </div>
            </div>
          </div>
        );
      })}
      {zone(blocks.length, true)}
    </div>
  );
}
