import type { ComponentType } from "react";
import {
  ArrowUpRight,
  Grid2x2,
  Heading,
  Image as ImageIcon,
  LayoutGrid,
  Minus,
  MoveVertical,
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
];
