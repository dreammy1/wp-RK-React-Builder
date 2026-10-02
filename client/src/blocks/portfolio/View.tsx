import type { ViewProps } from "@/render/ViewProps";
import { ContentGrid } from "../ContentGrid";
import type { PortfolioProps } from "./schema";

export function PortfolioView({ props, mode }: ViewProps<PortfolioProps>) {
  return (
    <ContentGrid
      title={props.title}
      query={props}
      cols={props.cols}
      noun="project"
      mode={mode}
      opts={props}
    />
  );
}
