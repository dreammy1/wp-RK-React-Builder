import type { ComponentType } from "react";
import { registry } from "@/blocks/registry";
import type { Block, LayoutDocument } from "@/lib/schema/layout";

/** One block → its registry View. The same code path serves the canvas and the public site. */
export function BlockRenderer({
  block,
  mode,
}: {
  block: Block;
  mode: "editor" | "public";
}) {
  const View = registry[block.type].View as ComponentType<{
    props: unknown;
    mode: "editor" | "public";
    blockId: string;
  }>;
  return <View props={block.props} mode={mode} blockId={block.id} />;
}

export function LayoutRenderer({
  layout,
  mode,
}: {
  layout: LayoutDocument;
  mode: "editor" | "public";
}) {
  return (
    <>
      {layout.blocks.map(block => (
        <BlockRenderer key={block.id} block={block} mode={mode} />
      ))}
    </>
  );
}
