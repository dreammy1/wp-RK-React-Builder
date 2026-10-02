import type { ComponentType } from "react";
import { registry } from "@/blocks/registry";
import type { ViewProps } from "@/render/ViewProps";
import { useReusable } from "@/render/reusable";
import type { ReusableProps } from "./schema";

/**
 * Renders the referenced block. Public output is exactly the block's own markup (no wrapper), so the PHP renderer and
 * the React views stay byte-for-byte equivalent. In the editor a labelled frame marks it as shared.
 */
export function ReusableView({
  props,
  mode,
  blockId,
}: ViewProps<ReusableProps>) {
  const record = useReusable(props.refId);
  if (!record) {
    return mode === "editor" ? (
      <div className="reusable-missing" role="note">
        This reusable block was deleted or could not be loaded. Detach it or
        remove it from the page.
      </div>
    ) : null;
  }
  const View = registry[record.block.type].View as ComponentType<{
    props: unknown;
    mode: "editor" | "public";
    blockId: string;
  }>;
  const inner = (
    <View props={record.block.props} mode={mode} blockId={`${blockId}-inner`} />
  );
  if (mode === "public") return inner;
  return (
    <div className="reusable-frame">
      <span className="reusable-tag">Reusable · {record.name}</span>
      {inner}
    </div>
  );
}
