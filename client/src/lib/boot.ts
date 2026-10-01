/** Boot object printed by the WordPress plugin when the builder runs inside wp-admin (nonce mode). */
export type BuilderBoot = {
  mode: "nonce";
  apiBase: string;
  nonce: string;
  publicSiteUrl?: string;
  /** wp-admin URL of the builder screen, e.g. https://cms.example/wp-admin/admin.php?page=rk-builder */
  adminUrl?: string;
  currentUser?: {
    id: number;
    name: string;
    capabilities: { manageTheme: boolean; publish: boolean };
  };
};

declare global {
  interface Window {
    RK_BUILDER_BOOT?: BuilderBoot;
  }
}

export const getBoot = (): BuilderBoot | undefined =>
  typeof window === "undefined" ? undefined : window.RK_BUILDER_BOOT;
