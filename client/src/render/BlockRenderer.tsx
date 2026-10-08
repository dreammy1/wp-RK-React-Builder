import type { ComponentType } from "react";
import { registry } from "@/blocks/registry";
import type { Block, LayoutDocument } from "@/lib/schema/layout";
import { layoutStyles, styleClass } from "@/lib/schema/style";

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
  // Advanced styles are emitted once per layout, scoped to each block's style class. A block only
  // gets a wrapper + class when it actually has styles, so unstyled documents render exactly as
  // before (and stay byte-identical to the PHP renderer).
  const styles = layoutStyles(
    layout.blocks,
    mode === "editor" ? "editor" : "public"
  );
  return (
    <>
      {styles && <style>{styles}</style>}
      {layout.blocks.map(block =>
        block.advanced ? (
          <div key={block.id} className={styleClass(block.id)}>
            <BlockRenderer block={block} mode={mode} />
          </div>
        ) : (
          <BlockRenderer key={block.id} block={block} mode={mode} />
        )
      )}
    </>
  );
}
