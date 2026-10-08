import { useId } from "react";
import { RotateCcw } from "lucide-react";
import type { AdvancedStyle } from "@/lib/schema/style";
import { Control, Section } from "./StyleControls";

/**
 * The "Advanced" tab: the block's identity in the editor and the escape hatches that do not fit the
 * visual controls — a friendly name for the layers panel, a stable CSS class for hand-written
 * styles, and a way to clear responsive overrides.
 *
 * Nothing here affects layout on its own, which keeps it safe to expose to any editor.
 */
export function AdvancedPanel({
  style,
  hasResponsive,
  onPatch,
  onResetResponsive,
}: {
  style: AdvancedStyle;
  hasResponsive: boolean;
  onPatch: (patch: Record<string, unknown>) => void;
  onResetResponsive: () => void;
}) {
  const nameId = useId();
  const classId = useId();
  const s = style ?? {};

  return (
    <div className="sx-panel">
      <Section title="Block identity" defaultOpen>
        <Control label="Name" htmlFor={nameId} hint="Shown in the editor only.">
          <input
            id={nameId}
            className="sx-input-el"
            type="text"
            maxLength={60}
            value={s.name ?? ""}
            placeholder="e.g. Hero — homepage"
            onChange={e =>
              onPatch({ name: e.target.value.trim() || undefined })
            }
          />
        </Control>
        <Control
          label="CSS class"
          htmlFor={classId}
          hint="Lowercase letters, numbers and dashes."
        >
          <input
            id={classId}
            className="sx-input-el"
            type="text"
            maxLength={40}
            spellCheck={false}
            value={s.cssClass ?? ""}
            placeholder="my-custom-class"
            onChange={e => {
              const v = e.target.value
                .toLowerCase()
                .replace(/[^a-z0-9-]/g, "")
                .slice(0, 40);
              onPatch({ cssClass: v || undefined });
            }}
          />
        </Control>
      </Section>

      <Section title="Responsive" defaultOpen>
        <p className="sx-hint">
          {hasResponsive
            ? "This block has tablet or phone overrides."
            : "No device-specific overrides yet. Switch device to add them."}
        </p>
        <button
          type="button"
          className="sx-btn"
          disabled={!hasResponsive}
          onClick={onResetResponsive}
        >
          <RotateCcw size={13} aria-hidden="true" /> Reset device overrides
        </button>
      </Section>
    </div>
  );
}
