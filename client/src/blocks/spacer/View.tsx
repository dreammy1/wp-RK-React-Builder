import type { ViewProps } from "@/render/ViewProps";
import type { SpacerProps } from "./schema";

export function SpacerView({ props }: ViewProps<SpacerProps>) {
  return (
    <div
      className="site-spacer"
      style={{ height: props.h }}
      aria-hidden="true"
    />
  );
}
