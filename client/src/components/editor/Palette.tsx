import { Plus } from "lucide-react";
import { PALETTE_ORDER, registry } from "@/blocks/registry";
import type { BlockType } from "@/lib/schema/primitives";
import type { ReusableItem } from "@/lib/schema/api";
import { Library } from "lucide-react";

export function Palette({
  onAdd,
  onDragStart,
  full,
  reusables,
  onAddReusable,
}: {
  reusables: ReusableItem[];
  onAddReusable: (id: number) => void;
  onAdd: (t: BlockType) => void;
  onDragStart: (t: BlockType | null) => void;
  full: boolean;
}) {
  return (
    <nav className="palette" aria-label="Block palette">
      <div className="rail-head">
        <span className="eyebrow">insert / block</span>
      </div>
      <ul className="palette-list">
        {PALETTE_ORDER.map(type => {
          const def = registry[type];
          const Icon = def.icon;
          return (
            <li key={type}>
              <button
                className="palette-item"
                disabled={full}
                draggable
                onDragStart={e => {
                  e.dataTransfer.setData("text/plain", type);
                  onDragStart(type);
                }}
                onDragEnd={() => onDragStart(null)}
                onClick={() => onAdd(type)}
                aria-label={`Add ${def.label} block`}
              >
                <span className="block-symbol">
                  <Icon size={14} aria-hidden="true" />
                </span>
                <span>
                  <strong>{def.label}</strong>
                  <small>{def.description}</small>
                </span>
                <Plus size={14} aria-hidden="true" />
              </button>
            </li>
          );
        })}
      </ul>
      {reusables.length > 0 && (
        <>
          <div className="rail-head">
            <span className="eyebrow">reusable / library</span>
          </div>
          <ul className="palette-list" aria-label="Reusable blocks">
            {reusables.map(r => {
              const Icon = registry[r.block.type].icon;
              return (
                <li key={r.id}>
                  <button
                    className="palette-item"
                    disabled={full}
                    onClick={() => onAddReusable(r.id)}
                    aria-label={`Add reusable ${r.name}`}
                  >
                    <span className="block-symbol">
                      <Icon size={14} aria-hidden="true" />
                    </span>
                    <span>
                      <strong>{r.name}</strong>
                      <small>
                        <Library size={10} aria-hidden="true" />{" "}
                        {registry[r.block.type].label}, linked
                      </small>
                    </span>
                    <Plus size={14} aria-hidden="true" />
                  </button>
                </li>
              );
            })}
          </ul>
        </>
      )}
      <div className="rail-note">
        <span className="eyebrow">data model</span>
        <p>
          Grids read live from WordPress. Edit content in WP, arrange sections
          here.
        </p>
        <code>layout v1</code>
      </div>
    </nav>
  );
}
