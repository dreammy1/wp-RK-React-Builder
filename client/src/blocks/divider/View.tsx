import type { ViewProps } from "@/render/ViewProps";
import type { DividerProps } from "./schema";

export function DividerView({ props }: ViewProps<DividerProps>) {
  return <hr className={`site-divider ${props.style}`} />;
}
