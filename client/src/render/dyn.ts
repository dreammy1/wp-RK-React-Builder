import { createContext, useContext } from "react";
import type { ContentType, TemplateItem } from "@/lib/schema/api";

/**
 * What the dynamic blocks need to know while you edit: which content type the template is for, the site's types
 * (to offer their fields) and its card templates. Empty on ordinary pages.
 */
export type DynContextValue = {
  postType: string;
  types: ContentType[];
  templates: TemplateItem[];
  /** Entry to preview with; the newest published one when unset. */
  sampleId?: number;
  /** True inside the editor: dynamic blocks fetch their markup from PHP (the public site never renders them in React). */
  active?: boolean;
};

export const DynContext = createContext<DynContextValue>({
  postType: "",
  types: [],
  templates: [],
});
export const useDyn = () => useContext(DynContext);

export type SourceKind = "scalar" | "image" | "gallery" | "repeater";

const BASE_SCALARS = [
  { value: "title", label: "Title" },
  { value: "excerpt", label: "Summary" },
  { value: "content", label: "Description" },
  { value: "date", label: "Published date" },
  { value: "modified", label: "Updated date" },
  { value: "readtime", label: "Reading time" },
  { value: "author", label: "Author" },
];

/** The choices for a source dropdown, from the template's content type. */
export function sourceOptions(
  type: ContentType | undefined,
  kind: SourceKind
): { value: string; label: string }[] {
  const fields = type?.fields ?? [];
  if (kind === "image")
    return [
      { value: "featured", label: "Featured image" },
      ...fields
        .filter(f => f.type === "image")
        .map(f => ({ value: `field:${f.key}`, label: f.label })),
    ];
  if (kind === "gallery" || kind === "repeater")
    return [
      { value: "", label: `Choose a ${kind} field` },
      ...fields
        .filter(f => f.type === kind)
        .map(f => ({ value: `field:${f.key}`, label: f.label })),
    ];
  const terms = (type?.taxonomyTerms ?? []).map(t => ({
    value: `terms:${t.slug}`,
    label: t.name,
  }));
  return [
    ...BASE_SCALARS,
    ...terms,
    ...fields
      .filter(f => f.type !== "gallery" && f.type !== "repeater")
      .map(f => ({ value: `field:${f.key}`, label: f.label })),
  ];
}
