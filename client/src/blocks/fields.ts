/** Declarative inspector fields. Rendered by components/editor/FieldsForm. */
export type FieldDef = FieldBase & FieldVariant;

type FieldBase = {
  /** Hide the field unless this returns true for the block's current values. */
  showIf?: (values: Record<string, unknown>) => boolean;
};

type FieldVariant =
  | {
      kind: "text" | "url" | "textarea";
      key: string;
      label: string;
      maxLength: number;
      help?: string;
    }
  | {
      kind: "number";
      key: string;
      label: string;
      min: number;
      max: number;
      help?: string;
    }
  | {
      kind: "select";
      key: string;
      label: string;
      options: { value: string | number; label: string }[];
      numeric?: boolean;
    }
  | {
      kind: "checkbox";
      key: string;
      label: string;
      help?: string;
      /** Shown ticked while the value is absent (for options that are on by default). */
      defaultOn?: boolean;
    }
  | {
      kind: "media";
      key: string;
      label: string;
      /** Decorative single-image mode: writes only `key` (url) and `idKey` (attachment id). */
      idKey?: string;
      optional?: boolean;
    }
  | { kind: "group"; label: string }
  | {
      /** Where a dynamic block reads from: the entry's title, a field, terms... Options come from the template's type. */
      kind: "dynSource";
      key: string;
      label: string;
      accept: "scalar" | "image" | "gallery" | "repeater";
      help?: string;
    }
  | { kind: "dynSources"; key: string; label: string; help?: string }
  | { kind: "postType"; key: string; label: string }
  | { kind: "taxonomy"; key: string; label: string; help?: string }
  | { kind: "taxonomyTerm"; key: string; label: string }
  | { kind: "loopTemplate"; key: string; label: string; help?: string }
  | {
      /** Photo-by-photo editor for the gallery block (media picker, order, category and caption): writes `items`. */
      kind: "galleryItems";
      key: "items";
      label: string;
    }
  | {
      /** Card-by-card editor for the catalog block: writes `items`, `modals`, `modalLabel` and `modalCta` together. */
      kind: "catalogCards";
      key: "items";
      label: string;
    };
