export type ViewProps<P> = {
  props: P;
  /** "editor" renders inside the canvas; "public" is server-rendered output. */
  mode: "editor" | "public";
  blockId: string;
};
