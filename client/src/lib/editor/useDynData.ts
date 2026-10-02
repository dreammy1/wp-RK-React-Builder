import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { getBoot } from "@/lib/boot";
import type { ContentType, TemplateItem } from "@/lib/schema/api";

/** The site's content types and card templates, for the dynamic blocks' pickers. WordPress-hosted editor only. */
export function useDynData(): {
  types: ContentType[];
  templates: TemplateItem[];
} {
  const [types, setTypes] = useState<ContentType[]>([]);
  const [templates, setTemplates] = useState<TemplateItem[]>([]);
  useEffect(() => {
    if (getBoot()?.mode !== "nonce") return;
    let dead = false;
    api
      .getTypes()
      .then(r => !dead && setTypes(r.types))
      .catch(() => undefined);
    api
      .listTemplates()
      .then(r => !dead && setTemplates(r.items))
      .catch(() => undefined);
    return () => {
      dead = true;
    };
  }, []);
  return { types, templates };
}
