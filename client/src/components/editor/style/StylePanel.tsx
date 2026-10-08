import { Image as ImageIcon, Trash2 } from "lucide-react";
import type { MediaItem } from "@/lib/schema/api";
import type { AdvancedStyle, Breakpoint } from "@/lib/schema/style";
import {
  BACKGROUND_GRADIENTS,
  BORDER_WIDTH_PRESETS,
  FONT_SIZE_PRESETS,
  RADIUS_PRESETS,
  SHADOW_PRESETS,
  SPACE_PRESETS,
  WIDTH_PRESETS,
  type FieldKey,
} from "@/lib/editor/styleModel";
import {
  BoxSides,
  ColorField,
  Control,
  NumberField,
  Section,
  SelectField,
  ToggleField,
} from "./StyleControls";
import { useState } from "react";
import { MediaPicker } from "../MediaPicker";

/**
 * The "Style" half of the inspector: background, typography, spacing, border, shadow and
 * visibility for the block currently selected.
 *
 * It is deliberately dumb about *where* the values go: it reads one flat style object and calls
 * `onPatch(field, value)`. The caller decides whether that lands on the base style or on the
 * override for the active breakpoint (see editor/styleModel.ts), which is what makes the same panel
 * work for desktop, tablet and phone editing.
 */

export function StylePanel({
  style,
  breakpoint,
  onPatch,
}: {
  style: AdvancedStyle;
  breakpoint: Breakpoint;
  onPatch: (field: FieldKey, value: unknown) => void;
}) {
  const [pickingBg, setPickingBg] = useState(false);
  const s = style ?? {};
  const background = s.background ?? {};
  const typography = s.typography ?? {};
  const border = s.border ?? {};
  const shadow = s.shadow ?? {};
  const visibility = s.visibility ?? {};

  return (
    <div className="sx-panel">
      <p className="sx-scope" role="note">
        {breakpoint === "base"
          ? "Editing the default style — applies to every device."
          : `Editing ${breakpoint} only. Values here override the default.`}
      </p>

      <Section title="Background" defaultOpen badge={swatch(background.color)}>
        <ColorField
          label="Background colour"
          value={background.color}
          allowTransparent
          onChange={v => onPatch("background", { ...background, color: v })}
        />
        <SelectField
          label="Gradient"
          value={background.gradient}
          options={BACKGROUND_GRADIENTS}
          unsetLabel="None"
          onChange={v => onPatch("background", { ...background, gradient: v })}
        />
        <Control label="Background image">
          {background.imageUrl ? (
            <div className="sx-media-row">
              <img className="sx-media-thumb" src={background.imageUrl} alt="" />
              <button
                type="button"
                className="sx-btn"
                onClick={() =>
                  onPatch("background", {
                    ...background,
                    imageUrl: undefined,
                    imageMediaId: undefined,
                  })
                }
              >
                <Trash2 size={13} aria-hidden="true" /> Remove
              </button>
            </div>
          ) : (
            <button
              type="button"
              className="sx-btn"
              onClick={() => setPickingBg(true)}
            >
              <ImageIcon size={13} aria-hidden="true" /> Choose image
            </button>
          )}
        </Control>
        {background.imageUrl && (
          <>
            <SelectField
              label="Image fit"
              value={background.imageFit}
              options={["cover", "contain", "fill"]}
              unsetLabel="Cover"
              onChange={v => onPatch("background", { ...background, imageFit: v })}
            />
            <SelectField
              label="Image position"
              value={background.imagePosition}
              options={["center", "top", "bottom", "left", "right"]}
              unsetLabel="Center"
              onChange={v =>
                onPatch("background", { ...background, imagePosition: v })
              }
            />
          </>
        )}
        {pickingBg && (
          <MediaPicker
            onClose={() => setPickingBg(false)}
            onSelect={(m: MediaItem) => {
              onPatch("background", {
                ...background,
                imageMediaId: m.id,
                imageUrl: m.url,
              });
              setPickingBg(false);
            }}
          />
        )}
      </Section>

      <Section
        title="Typography"
        badge={typography.size ? String(typography.size) : undefined}
      >
        <SelectField
          label="Heading size"
          value={typography.size}
          options={FONT_SIZE_PRESETS}
          unsetLabel="Theme default"
          onChange={v => onPatch("typography", { ...typography, size: v })}
        />
        <SelectField
          label="Heading weight"
          value={typography.weight}
          options={[300, 400, 500, 600, 700, 800, 900]}
          unsetLabel="Theme default"
          onChange={v =>
            onPatch("typography", {
              ...typography,
              weight: v === undefined ? undefined : Number(v),
            })
          }
        />
        <SelectField
          label="Text alignment"
          value={typography.align}
          options={["left", "center", "right"]}
          unsetLabel="As designed"
          onChange={v => onPatch("typography", { ...typography, align: v })}
        />
        <ColorField
          label="Text colour"
          value={typography.color}
          onChange={v => onPatch("typography", { ...typography, color: v })}
        />
      </Section>

      <Section title="Layout" defaultOpen>
        <SelectField
          label="Width"
          value={s.size?.width}
          options={WIDTH_PRESETS}
          unsetLabel="As designed"
          onChange={v => onPatch("size", { ...(s.size ?? {}), width: v })}
        />
        <Control label="Minimum height">
          <div className="sx-inline-pair">
            <NumberField
              label="Minimum height"
              value={pxToNumber(s.size?.minHeight)}
              min={0}
              max={2000}
              suffix="px"
              onChange={v =>
                onPatch("size", {
                  ...(s.size ?? {}),
                  minHeight: v === undefined ? undefined : `${v}px`,
                })
              }
            />
          </div>
        </Control>
        <BoxSides
          label="Padding"
          values={s.spacing ?? {}}
          presets={SPACE_PRESETS}
          onChange={patch => onPatch("spacing", { ...(s.spacing ?? {}), ...patch })}
        />
      </Section>

      <Section title="Border & shadow">
        <BoxSides
          label="Border width"
          values={{ top: border.width, right: border.width, bottom: border.width, left: border.width }}
          presets={BORDER_WIDTH_PRESETS}
          onChange={patch => {
            // A uniform box: any side edit sets the single width the schema stores.
            const next = patch.top ?? patch.right ?? patch.bottom ?? patch.left;
            onPatch("border", { ...border, width: next });
          }}
        />
        <SelectField
          label="Border style"
          value={border.style}
          options={["solid", "dashed", "dotted"]}
          unsetLabel="Solid"
          onChange={v => onPatch("border", { ...border, style: v })}
        />
        <ColorField
          label="Border colour"
          value={border.color}
          onChange={v => onPatch("border", { ...border, color: v })}
        />
        <SelectField
          label="Corner radius"
          value={border.radius}
          options={RADIUS_PRESETS}
          unsetLabel="As designed"
          onChange={v => onPatch("border", { ...border, radius: v })}
        />
        <SelectField
          label="Shadow"
          value={shadow.preset}
          options={SHADOW_PRESETS}
          unsetLabel="None"
          onChange={v => onPatch("shadow", { preset: v })}
        />
      </Section>

      <Section title="Visibility">
        <ToggleField
          label="Hide on desktop"
          checked={Boolean(visibility.hideDesktop)}
          onChange={v => onPatch("visibility", { ...visibility, hideDesktop: v })}
        />
        <ToggleField
          label="Hide on tablet"
          checked={Boolean(visibility.hideTablet)}
          onChange={v => onPatch("visibility", { ...visibility, hideTablet: v })}
        />
        <ToggleField
          label="Hide on phone"
          checked={Boolean(visibility.hidePhone)}
          onChange={v => onPatch("visibility", { ...visibility, hidePhone: v })}
        />
      </Section>
    </div>
  );
}

const pxToNumber = (v: string | undefined): number | undefined => {
  if (!v) return undefined;
  const n = Number(v.replace("px", ""));
  return Number.isFinite(n) ? n : undefined;
};

/** A tiny colour chip shown on a collapsed section so you can see what is set at a glance. */
const swatch = (color: string | undefined) =>
  color ? (
    <span
      className="sx-swatch"
      aria-hidden="true"
      style={{ background: color === "transparent" ? "transparent" : color }}
    />
  ) : undefined;
