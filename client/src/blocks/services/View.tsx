import type { ViewProps } from "@/render/ViewProps";
import { ContentGrid } from "../ContentGrid";
import type { ServicesProps } from "./schema";

export function ServicesView({ props, mode }: ViewProps<ServicesProps>) {
  return (
    <ContentGrid
      title={props.title}
      query={props}
      cols={props.cols}
      noun="service"
      mode={mode}
    />
  );
}
