import { Monitor, Smartphone, Tablet } from "lucide-react";
import type { Breakpoint } from "@/lib/schema/style";

const DEVICES: { id: Breakpoint; label: string; Icon: typeof Monitor }[] = [
  { id: "base", label: "Desktop", Icon: Monitor },
  { id: "tablet", label: "Tablet", Icon: Tablet },
  { id: "phone", label: "Phone", Icon: Smartphone },
];

/**
 * The device switcher that scopes the style panel. Choosing a device does two things: it tells the
 * panel which breakpoint its edits land on, and it narrows the canvas so you see the result. It is
 * a radio group semantically, so arrow keys move between devices.
 */
export function DeviceSwitcher({
  value,
  onChange,
  overridden,
}: {
  value: Breakpoint;
  onChange: (b: Breakpoint) => void;
  /** Which breakpoints currently carry overrides, so the UI can mark them. */
  overridden?: Partial<Record<Breakpoint, boolean>>;
}) {
  return (
    <div className="device-switch sx-devices" role="radiogroup" aria-label="Editing device">
      {DEVICES.map(({ id, label, Icon }) => (
        <button
          key={id}
          type="button"
          role="radio"
          aria-checked={value === id}
          aria-label={label}
          title={label}
          className={value === id ? "on" : ""}
          onClick={() => onChange(id)}
        >
          <Icon size={15} aria-hidden="true" />
          <span className="sx-device-label">{label}</span>
          {overridden?.[id] && <i className="sx-device-dot" aria-hidden="true" />}
        </button>
      ))}
    </div>
  );
}
