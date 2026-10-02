import type { ComponentType } from "react";
import {
  ArrowUpRight,
  Grid2x2,
  Heading,
  Image as ImageIcon,
  LayoutGrid,
  Minus,
  MoveVertical,
  Library,
  PanelTop,
  PanelBottom,
  Columns2,
  Type,
  Phone as PhoneIcon,
  LayoutPanelLeft,
  Quote,
  LayoutTemplate,
  FileText,
  Images,
  Calculator,
  Tags,
  MapPin,
  MessageSquareQuote,
  Pilcrow,
  Rocket,
  type LucideIcon,
} from "lucide-react";
import type { ZodType } from "zod";
import type { Block } from "@/lib/schema/layout";
import type { BlockType } from "@/lib/schema/primitives";
import type { ViewProps } from "@/render/ViewProps";
import type { FieldDef } from "./fields";
import { heroDefaults, heroProps } from "./hero/schema";
import { HeroView } from "./hero/View";
import { heroFields } from "./hero/Editor";
import { headingDefaults, headingProps } from "./heading/schema";
import { HeadingView } from "./heading/View";
import { headingFields } from "./heading/Editor";
import { textDefaults, textProps } from "./text/schema";
import { TextView } from "./text/View";
import { textFields } from "./text/Editor";
import { imageDefaults, imageProps } from "./image/schema";
import { ImageView } from "./image/View";
import { imageFields } from "./image/Editor";
import { ctaDefaults, ctaProps } from "./cta/schema";
import { CtaView } from "./cta/View";
import { ctaFields } from "./cta/Editor";
import { servicesDefaults, servicesProps } from "./services/schema";
import { ServicesView } from "./services/View";
import { servicesFields } from "./services/Editor";
import { portfolioDefaults, portfolioProps } from "./portfolio/schema";
import { PortfolioView } from "./portfolio/View";
import { portfolioFields } from "./portfolio/Editor";
import { spacerDefaults, spacerProps } from "./spacer/schema";
import { SpacerView } from "./spacer/View";
import { spacerFields } from "./spacer/Editor";
import { dividerDefaults, dividerProps } from "./divider/schema";
import { DividerView } from "./divider/View";
import { dividerFields } from "./divider/Editor";
import { testimonialDefaults, testimonialProps } from "./testimonial/schema";
import { TestimonialView } from "./testimonial/View";
import { testimonialFields } from "./testimonial/Editor";
import { contactDefaults, contactProps } from "./contact/schema";
import { ContactView } from "./contact/View";
import { contactFields } from "./contact/Editor";
import { navbarDefaults, navbarProps } from "./navbar/schema";
import { NavbarView } from "./navbar/View";
import { navbarFields } from "./navbar/Editor";
import { coverheroDefaults, coverheroProps } from "./coverhero/schema";
import { CoverheroView } from "./coverhero/View";
import { coverheroFields } from "./coverhero/Editor";
import { sitefooterDefaults, sitefooterProps } from "./sitefooter/schema";
import { SitefooterView } from "./sitefooter/View";
import { sitefooterFields } from "./sitefooter/Editor";
import { sectionDefaults, sectionProps } from "./section/schema";
import { SectionView } from "./section/View";
import { sectionFields } from "./section/Editor";
import { splitDefaults, splitProps } from "./split/schema";
import { SplitView } from "./split/View";
import { splitFields } from "./split/Editor";
import { contactbandDefaults, contactbandProps } from "./contactband/schema";
import { ContactbandView } from "./contactband/View";
import { contactbandFields } from "./contactband/Editor";
import { panelDefaults, panelProps } from "./panel/schema";
import { PanelView } from "./panel/View";
import { panelFields } from "./panel/Editor";
import { valuesDefaults, valuesProps } from "./values/schema";
import { ValuesView } from "./values/View";
import { valuesFields } from "./values/Editor";
import { catalogDefaults, catalogProps } from "./catalog/schema";
import { CatalogView } from "./catalog/View";
import { catalogFields } from "./catalog/Editor";
import { detailDefaults, detailProps } from "./detail/schema";
import { DetailView } from "./detail/View";
import { detailFields } from "./detail/Editor";
import { galleryDefaults, galleryProps } from "./gallery/schema";
import { GalleryView } from "./gallery/View";
import { galleryFields } from "./gallery/Editor";
import { brandstripDefaults, brandstripProps } from "./brandstrip/schema";
import { BrandstripView } from "./brandstrip/View";
import { brandstripFields } from "./brandstrip/Editor";
import { calculatorDefaults, calculatorProps } from "./calculator/schema";
import { CalculatorView } from "./calculator/View";
import { calculatorFields } from "./calculator/Editor";
import { reusableDefaults, reusableProps } from "./reusable/schema";
import { ReusableView } from "./reusable/View";
import { reusableFields } from "./reusable/Editor";

export type PropsOf<T extends BlockType> = Extract<Block, { type: T }>["props"];

export type BlockDefinition<T extends BlockType> = {
  type: T;
  label: string;
  icon: LucideIcon;
  description: string;
  defaults: PropsOf<T>;
  schema: ZodType<PropsOf<T>>;
  View: ComponentType<ViewProps<PropsOf<T>>>;
  fields: FieldDef[];
  /** Upgrade props saved by an older block schema. No migrations are needed at v1. */
  migrate?: (
    props: Record<string, unknown>,
    fromVersion: number
  ) => Record<string, unknown>;
};

type Registry = { [T in BlockType]: BlockDefinition<T> };

export const registry: Registry = {
  hero: {
    type: "hero",
    label: "Hero",
    icon: Rocket,
    description: "Lead with a clear proposition",
    defaults: heroDefaults,
    schema: heroProps,
    View: HeroView,
    fields: heroFields,
  },
  heading: {
    type: "heading",
    label: "Heading",
    icon: Heading,
    description: "Create hierarchy",
    defaults: headingDefaults,
    schema: headingProps,
    View: HeadingView,
    fields: headingFields,
  },
  text: {
    type: "text",
    label: "Text",
    icon: Pilcrow,
    description: "Plain-text body copy",
    defaults: textDefaults,
    schema: textProps,
    View: TextView,
    fields: textFields,
  },
  image: {
    type: "image",
    label: "Image",
    icon: ImageIcon,
    description: "A WordPress media asset",
    defaults: imageDefaults,
    schema: imageProps,
    View: ImageView,
    fields: imageFields,
  },
  cta: {
    type: "cta",
    label: "CTA banner",
    icon: ArrowUpRight,
    description: "Close with an action",
    defaults: ctaDefaults,
    schema: ctaProps,
    View: CtaView,
    fields: ctaFields,
  },
  services: {
    type: "services",
    label: "Services grid",
    icon: Grid2x2,
    description: "Live Service posts",
    defaults: servicesDefaults,
    schema: servicesProps,
    View: ServicesView,
    fields: servicesFields,
  },
  portfolio: {
    type: "portfolio",
    label: "Portfolio grid",
    icon: LayoutGrid,
    description: "Live Portfolio posts",
    defaults: portfolioDefaults,
    schema: portfolioProps,
    View: PortfolioView,
    fields: portfolioFields,
  },
  spacer: {
    type: "spacer",
    label: "Spacer",
    icon: MoveVertical,
    description: "Tune vertical rhythm",
    defaults: spacerDefaults,
    schema: spacerProps,
    View: SpacerView,
    fields: spacerFields,
  },
  divider: {
    type: "divider",
    label: "Divider",
    icon: Minus,
    description: "A horizontal rule",
    defaults: dividerDefaults,
    schema: dividerProps,
    View: DividerView,
    fields: dividerFields,
  },
  testimonial: {
    type: "testimonial",
    label: "Testimonial",
    icon: MessageSquareQuote,
    description: "A customer quote",
    defaults: testimonialDefaults,
    schema: testimonialProps,
    View: TestimonialView,
    fields: testimonialFields,
  },
  contact: {
    type: "contact",
    label: "Contact details",
    icon: MapPin,
    description: "Phone, email, address, hours",
    defaults: contactDefaults,
    schema: contactProps,
    View: ContactView,
    fields: contactFields,
  },
  navbar: {
    type: "navbar",
    label: "Header / menu",
    icon: PanelTop,
    description: "Logo, links and a phone button",
    defaults: navbarDefaults,
    schema: navbarProps,
    View: NavbarView,
    fields: navbarFields,
  },
  coverhero: {
    type: "coverhero",
    label: "Cover hero",
    icon: ImageIcon,
    description: "Full-bleed photo with a dark overlay",
    defaults: coverheroDefaults,
    schema: coverheroProps,
    View: CoverheroView,
    fields: coverheroFields,
  },
  sitefooter: {
    type: "sitefooter",
    label: "Site footer",
    icon: PanelBottom,
    description: "Link columns, contact and small print",
    defaults: sitefooterDefaults,
    schema: sitefooterProps,
    View: SitefooterView,
    fields: sitefooterFields,
  },
  section: {
    type: "section",
    label: "Section intro",
    icon: Type,
    description: "Eyebrow, serif heading, copy and a link",
    defaults: sectionDefaults,
    schema: sectionProps,
    View: SectionView,
    fields: sectionFields,
  },
  split: {
    type: "split",
    label: "Image + copy",
    icon: Columns2,
    description: "Photo beside a heading, copy and key facts",
    defaults: splitDefaults,
    schema: splitProps,
    View: SplitView,
    fields: splitFields,
  },
  contactband: {
    type: "contactband",
    label: "Contact band",
    icon: PhoneIcon,
    description: "Closing band with call and email cards",
    defaults: contactbandDefaults,
    schema: contactbandProps,
    View: ContactbandView,
    fields: contactbandFields,
  },
  panel: {
    type: "panel",
    label: "Copy + rows panel",
    icon: LayoutPanelLeft,
    description: "Copy beside divider rows, or heading beside checks",
    defaults: panelDefaults,
    schema: panelProps,
    View: PanelView,
    fields: panelFields,
  },
  values: {
    type: "values",
    label: "Value cards",
    icon: Quote,
    description: "Bordered cards in a row",
    defaults: valuesDefaults,
    schema: valuesProps,
    View: ValuesView,
    fields: valuesFields,
  },
  catalog: {
    type: "catalog",
    label: "Card catalog",
    icon: LayoutTemplate,
    description: "Photo or swatch cards with specs and bullets",
    defaults: catalogDefaults,
    schema: catalogProps,
    View: CatalogView,
    fields: catalogFields,
  },
  detail: {
    type: "detail",
    label: "Service detail",
    icon: FileText,
    description: "Steps, factors, FAQ and a sticky sidebar",
    defaults: detailDefaults,
    schema: detailProps,
    View: DetailView,
    fields: detailFields,
  },
  gallery: {
    type: "gallery",
    label: "Photo gallery",
    icon: Images,
    description: "Captioned photo grid",
    defaults: galleryDefaults,
    schema: galleryProps,
    View: GalleryView,
    fields: galleryFields,
  },
  calculator: {
    type: "calculator",
    label: "Estimate calculator",
    icon: Calculator,
    description: "Pick a project type and size for a planning range",
    defaults: calculatorDefaults,
    schema: calculatorProps,
    View: CalculatorView,
    fields: calculatorFields,
  },
  brandstrip: {
    type: "brandstrip",
    label: "Brand strip",
    icon: Tags,
    description: "A quiet row of partner or product names",
    defaults: brandstripDefaults,
    schema: brandstripProps,
    View: BrandstripView,
    fields: brandstripFields,
  },
  reusable: {
    type: "reusable",
    label: "Reusable block",
    icon: Library,
    description: "Shared content kept in the library",
    defaults: reusableDefaults,
    schema: reusableProps,
    View: ReusableView,
    fields: reusableFields,
  },
};

export const PALETTE_ORDER: BlockType[] = [
  "hero",
  "heading",
  "text",
  "image",
  "cta",
  "services",
  "portfolio",
  "spacer",
  "divider",
  "testimonial",
  "contact",
  "navbar",
  "coverhero",
  "sitefooter",
  "section",
  "split",
  "contactband",
  "panel",
  "values",
  "catalog",
  "detail",
  "gallery",
  "calculator",
  "brandstrip",
];
