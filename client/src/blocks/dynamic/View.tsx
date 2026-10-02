import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api/builder";
import { useDyn } from "@/render/dyn";
import type { ViewProps } from "@/render/ViewProps";

/**
 * The editor's picture of a dynamic block is the PHP renderer's own markup (POST builder/dyn/render), so what you
 * edit is what is published. On the public site these blocks are drawn by PHP only.
 */
export function DynPreview({
  type,
  props,
  mode,
}: {
  type: string;
  props: Record<string, unknown>;
  mode: "editor" | "public";
}) {
  const { postType, sampleId, active } = useDyn();
  const live = mode === "editor" || active === true;
  const own = typeof props.postType === "string" ? props.postType : "current";
  const effective = type === "loopgrid" && own !== "current" ? own : postType;
  const key = JSON.stringify([type, props, effective, sampleId]);
  const [shown, setShown] = useState<{
    key: string;
    html: string;
    valid: boolean;
  } | null>(null);
  const [failed, setFailed] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!live || !effective) return;
    const ctl = new AbortController();
    const t = setTimeout(() => {
      api
        .renderDyn({ type, props }, effective, sampleId, ctl.signal)
        .then(r => {
          setFailed(false);
          setShown({ key, html: r.html, valid: r.valid });
        })
        .catch(() => {
          if (!ctl.signal.aborted) setFailed(true);
        });
    }, 250);
    return () => {
      clearTimeout(t);
      ctl.abort();
    };
    // `key` already covers type, props, effective and sampleId.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, live]);

  if (!live) return null;
  if (!effective)
    return (
      <div className="dyn-hint">
        This block shows content from an entry. Build it inside a template
        (Dashboard &rsaquo; Templates), or pick a content type in its settings.
      </div>
    );
  if (failed && !shown)
    return <div className="dyn-hint">Could not load the preview.</div>;
  if (!shown) return <div className="dyn-hint">Loading preview…</div>;
  if (!shown.valid)
    return (
      <div className="dyn-hint">
        This block has a setting that is not valid. Check its settings.
      </div>
    );
  if (shown.html === "")
    return (
      <div className="dyn-hint">
        Nothing to show for the preview entry (empty field or no entry yet).
      </div>
    );
  return (
    <div
      ref={ref}
      className="dyn-preview"
      onClickCapture={e => {
        if ((e.target as HTMLElement).closest("a")) e.preventDefault();
      }}
      onSubmit={e => e.preventDefault()}
      // The markup comes from this site's own PHP renderer, which escapes every value.
      dangerouslySetInnerHTML={{ __html: shown.html }}
    />
  );
}

export const makeDynView =
  <P extends object>(type: string) =>
  ({ props, mode }: ViewProps<P>) => (
    <DynPreview
      type={type}
      props={props as Record<string, unknown>}
      mode={mode}
    />
  );
