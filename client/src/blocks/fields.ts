/** Declarative inspector fields. Rendered by components/editor/FieldsForm. */
export type FieldDef =
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
  | { kind: "checkbox"; key: string; label: string; help?: string }
  | {
      kind: "media";
      key: string;
      label: string;
      /** Decorative single-image mode: writes only `key` (url) and `idKey` (attachment id). */
      idKey?: string;
      optional?: boolean;
    };
