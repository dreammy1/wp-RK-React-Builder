import type { Block, LayoutDocument } from "@/lib/schema/layout";
import type { ThemeConfig } from "@/lib/schema/theme";

export type UiCategory = "theme" | "page" | "header" | "footer" | "section";

export type UiArchetype = "saas" | "minimal" | "agency" | "artisan" | "corporate";

export interface UiLibraryItem {
  id: string;
  name: string;
  category: UiCategory;
  archetype: UiArchetype;
  archetypeLabel: string;
  tagline: string;
  description: string;
  tags: string[];
  tokens: ThemeConfig;
  layout?: LayoutDocument;
  block?: Block;
}

export const ARCHETYPES: Record<
  UiArchetype,
  { label: string; description: string; tokens: ThemeConfig }
> = {
  saas: {
    label: "Modern SaaS / Tech",
    description: "Vibrant indigo & violet, deep slate ink, crisp sans-serif, soft 8px corners",
    tokens: {
      version: 1,
      primary: "#4F46E5",
      bg: "#FFFFFF",
      ink: "#0F172A",
      font: "System Sans",
      accent: "#6366F1",
      dark: "#0F172A",
      surface: "#F8FAFC",
      bodyFont: "System Sans",
      headingFont: "System Sans",
      headingWeight: 700,
      radius: "soft",
      buttonStyle: "solid",
    },
  },
  minimal: {
    label: "Minimalist Editorial",
    description: "Pure monochrome paper & ink, refined Georgia serif headings, razor-sharp edges",
    tokens: {
      version: 1,
      primary: "#111111",
      bg: "#FFFFFF",
      ink: "#1C1917",
      font: "System Sans",
      accent: "#78716C",
      dark: "#111111",
      surface: "#F5F5F4",
      bodyFont: "System Sans",
      headingFont: "Georgia",
      headingWeight: 500,
      radius: "square",
      buttonStyle: "outline",
    },
  },
  agency: {
    label: "Bold Dark Agency",
    description: "High-voltage neon lime on obsidian dark, Space Grotesk & IBM Plex Mono, pill badges",
    tokens: {
      version: 1,
      primary: "#C7F36B",
      bg: "#0B0F17",
      ink: "#F8FAFC",
      font: "Space Grotesk",
      accent: "#22C55E",
      dark: "#0B0F17",
      surface: "#161F30",
      bodyFont: "Space Grotesk",
      headingFont: "Space Grotesk",
      headingWeight: 700,
      radius: "round",
      buttonStyle: "solid",
    },
  },
  artisan: {
    label: "Warm Artisan Lifestyle",
    description: "Terracotta amber, warm cream linen, Humanist Sans & Classic Serif, soft curves",
    tokens: {
      version: 1,
      primary: "#A97C50",
      bg: "#FBF8F3",
      ink: "#1F1A17",
      font: "Humanist Sans",
      accent: "#8A5A2B",
      dark: "#1F1A17",
      surface: "#F1EADF",
      bodyFont: "Humanist Sans",
      headingFont: "Classic Serif",
      headingWeight: 500,
      radius: "soft",
      buttonStyle: "solid",
    },
  },
  corporate: {
    label: "Enterprise Corporate",
    description: "Trust navy & clinical azure, structured slate cards, clean corporate symmetry",
    tokens: {
      version: 1,
      primary: "#0F3A66",
      bg: "#F8FAFC",
      ink: "#0F172A",
      font: "System Sans",
      accent: "#0284C7",
      dark: "#0F172A",
      surface: "#EDF2F7",
      bodyFont: "System Sans",
      headingFont: "System Sans",
      headingWeight: 600,
      radius: "soft",
      buttonStyle: "solid",
    },
  },
};

export const UI_LIBRARY_ITEMS: UiLibraryItem[] = [
  /* =====================================================================
   * CATEGORY 1: THEMES (5 Variants)
   * ===================================================================== */
  {
    id: "th-apex-saas",
    name: "Apex SaaS & Platform",
    category: "theme",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "High-converting tech startup and product dashboard aesthetic",
    description:
      "Vibrant indigo primary (#4F46E5) with electric violet accents, cool slate neutrals, soft 8px radii, and crisp system sans typography.",
    tags: ["SaaS", "Tech", "Startup", "App", "Indigo"],
    tokens: ARCHETYPES.saas.tokens,
  },
  {
    id: "th-atelier-mono",
    name: "Atelier Mono Editorial",
    category: "theme",
    archetype: "minimal",
    archetypeLabel: ARCHETYPES.minimal.label,
    tagline: "High-fashion architecture and design studio aesthetic",
    description:
      "Uncompromising black & white monochrome styling with warm stone surfaces, Georgia serif titles, razor 0px borders, and refined outline buttons.",
    tags: ["Editorial", "Minimal", "Architecture", "Portfolio", "Monochrome"],
    tokens: ARCHETYPES.minimal.tokens,
  },
  {
    id: "th-cyber-studio",
    name: "Cyber Agency Dark",
    category: "theme",
    archetype: "agency",
    archetypeLabel: ARCHETYPES.agency.label,
    tagline: "Punchy Web3, AI studio, and digital lab aesthetic",
    description:
      "High-energy neon lime (#C7F36B) popping against deep obsidian surfaces (#0B0F17), Space Grotesk headings, and pill-shaped rounded badges.",
    tags: ["Agency", "Dark Mode", "Neon", "Creative", "Cyber"],
    tokens: ARCHETYPES.agency.tokens,
  },
  {
    id: "th-tuscan-amber",
    name: "Tuscan Amber Artisan",
    category: "theme",
    archetype: "artisan",
    archetypeLabel: ARCHETYPES.artisan.label,
    tagline: "Warm boutique lifestyle, coffee roasters, and luxury hospitality",
    description:
      "Earthy terracotta (#A97C50) paired with warm cream linen (#FBF8F3), classic serif typography, and natural tactile surfaces.",
    tags: ["Artisan", "Lifestyle", "Warm", "Hospitality", "Craft"],
    tokens: ARCHETYPES.artisan.tokens,
  },
  {
    id: "th-vanguard-navy",
    name: "Vanguard Corporate & FinTech",
    category: "theme",
    archetype: "corporate",
    archetypeLabel: ARCHETYPES.corporate.label,
    tagline: "Authoritative financial services and healthcare enterprise aesthetic",
    description:
      "Deep trust navy (#0F3A66) with sharp azure blue accents (#0284C7), high-legibility system typography, and structured slate containers.",
    tags: ["Corporate", "Finance", "Healthcare", "Enterprise", "Navy"],
    tokens: ARCHETYPES.corporate.tokens,
  },

  /* =====================================================================
   * CATEGORY 2: PAGE TEMPLATES (5 Variants)
   * ===================================================================== */
  {
    id: "pg-saas-growth",
    name: "SaaS Product Growth Landing",
    category: "page",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "Complete multi-section software landing page with live proof",
    description:
      "Features a modern sticky navbar, high-converting cover hero with dual CTAs, brand logo ticker, 3-pillar feature grid, social testimonials, and closing trial banner.",
    tags: ["SaaS", "Landing Page", "Software", "Conversion"],
    tokens: ARCHETYPES.saas.tokens,
    layout: {
      version: 1,
      blocks: [
        {
          id: "pg-saas-nav",
          type: "navbar",
          props: {
            brand: "Apex Cloud",
            links: "Features|#features\nSolutions|#solutions\nPricing|#pricing\nDocs|#docs",
            phone: "Sign In",
            phoneHref: "/login",
            overlay: false,
          },
        },
        {
          id: "pg-saas-hero",
          type: "coverhero",
          props: {
            crumb: "",
            eyebrow: "v3.0 Released · AI-Powered Analytics",
            heading: "Modern data infrastructure for ambitious teams",
            sub: "Ingest, transform, and visualize customer signals in real time without writing glue code.",
            cta: "Start 14-day trial",
            ctaHref: "/signup",
            cta2: "Schedule demo",
            cta2Href: "/demo",
            size: "screen",
          },
        },
        {
          id: "pg-saas-brands",
          type: "brandstrip",
          props: {
            label: "Trusted by engineering leaders worldwide",
            items: "Acme Corp\nStarlight AI\nNorthwind Data\nNexus Systems\nHyperScale",
          },
        },
        {
          id: "pg-saas-features",
          type: "section",
          props: {
            eyebrow: "Architecture",
            heading: "Built from the ground up for developer velocity",
            body: "Zero cold starts. Sub-millisecond latency. Automated end-to-end schema synchronization with your existing database.",
            linkLabel: "Explore documentation",
            linkHref: "/docs",
            tone: "light",
          },
        },
        {
          id: "pg-saas-services",
          type: "services",
          props: {
            title: "Core Capabilities",
            source: "service",
            limit: 3,
            cols: 3,
            category: "",
            orderBy: "menu_order",
            order: "asc",
          },
        },
        {
          id: "pg-saas-testi",
          type: "testimonial",
          props: {
            quote:
              "Apex transformed our pipeline execution speed by 10x. It is the cleanest developer experience we have integrated this year.",
            author: "Elena Rostova",
            role: "VP of Engineering at FinScale",
          },
        },
        {
          id: "pg-saas-cta",
          type: "cta",
          props: {
            heading: "Start building faster today. No credit card required.",
            cta: "Get Started Now",
            ctaHref: "/signup",
          },
        },
        {
          id: "pg-saas-footer",
          type: "sitefooter",
          props: {
            brand: "Apex Cloud, Inc.",
            tagline: "Next generation cloud telemetry and visual orchestration.",
            colATitle: "Product",
            colALinks: "Overview|/overview\nChangelog|/changelog\nSecurity|/security",
            colBTitle: "Company",
            colBLinks: "About Us|/about\nCareers|/careers\nContact|/contact",
            contactTitle: "Support",
            phone: "",
            email: "support@apexcloud.dev",
            address: "",
            copyright: "© 2026 Apex Cloud Inc. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "pg-editorial-studio",
    name: "Editorial Creative Studio Showcase",
    category: "page",
    archetype: "minimal",
    archetypeLabel: ARCHETYPES.minimal.label,
    tagline: "Oversized typography and minimalist portfolio layout",
    description:
      "Tailored for architects, designers, and creative directors with numbered principles, case study showcase, and understated inquiry band.",
    tags: ["Minimal", "Portfolio", "Studio", "Architecture"],
    tokens: ARCHETYPES.minimal.tokens,
    layout: {
      version: 1,
      blocks: [
        {
          id: "pg-edit-nav",
          type: "navbar",
          props: {
            brand: "ATELIER MONO",
            links: "WORKS|/works\nPHILOSOPHY|/philosophy\nEXHIBITIONS|/exhibitions\nCONTACT|/contact",
            phone: "",
            phoneHref: "",
            overlay: false,
          },
        },
        {
          id: "pg-edit-hero",
          type: "hero",
          props: {
            heading: "We design enduring physical and digital spaces.",
            sub: "A multidisciplinary architecture and visual strategy studio operating between London and Tokyo.",
            cta: "View Monograph",
            ctaHref: "/works",
          },
        },
        {
          id: "pg-edit-values",
          type: "values",
          props: {
            eyebrow: "Our Manifesto",
            heading: "Three Foundational Tenets",
            items:
              "01 Spatial Clarity|Removing excess until only functional essence and natural light remain.\n02 Tactile Materiality|Honoring raw limestone, cast iron, and unbleached Belgian linen.\n03 Generational Permanence|Creating environments designed to age gracefully over decades.",
            cols: 3,
            tone: "muted",
          },
        },
        {
          id: "pg-edit-split",
          type: "split",
          props: {
            eyebrow: "Recent Commission",
            heading: "Pavilion 47: Light & Monolithic Concrete",
            body: "Completed in autumn 2025 for the Kyoto Contemporary Art Triennial. A contemplative pavilion investigating shadow and temporal acoustic reflection.",
            facts: "Kyoto|Commission Location\n2025|Completion Year",
            cta: "View Case Study",
            ctaHref: "/works/pavilion-47",
            imageAlt: "Pavilion 47 monolithic concrete structure",
            side: "right",
            tone: "light",
            checks: "Winner: International Architecture Medal\nPublished in Architectural Record Q1 2026",
          },
        },
        {
          id: "pg-edit-band",
          type: "contactband",
          props: {
            heading: "Currently accepting architectural commissions for 2027.",
            sub: "Direct all project inquiries to our London studio partner.",
            phone: "",
            email: "partners@ateliermono.design",
          },
        },
        {
          id: "pg-edit-footer",
          type: "sitefooter",
          props: {
            brand: "ATELIER MONO",
            tagline: "London 16:30 GMT · Tokyo 01:30 JST",
            colATitle: "Studio",
            colALinks: "Works|/works\nPhilosophy|/philosophy\nMonograph|/monograph",
            colBTitle: "Inquiries",
            colBLinks: "Commissions|/commissions\nPress|/press\nCareers|/careers",
            contactTitle: "London Atelier",
            phone: "+44 20 7946 0912",
            email: "contact@ateliermono.design",
            address: "14 Berwick Street, Soho, London W1F 0PP",
            copyright: "© 2026 Atelier Mono Ltd. Registered in England.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "pg-agency-dark",
    name: "Digital Agency Dark Launch",
    category: "page",
    archetype: "agency",
    archetypeLabel: ARCHETYPES.agency.label,
    tagline: "High-contrast neon agency site with bold interactive panels",
    description:
      "Obsidian dark background, neon status badges, capability breakdown, authentic client reviews, and an unmistakable call-to-action.",
    tags: ["Agency", "Dark Mode", "Neon", "Creative", "Bold"],
    tokens: ARCHETYPES.agency.tokens,
    layout: {
      version: 1,
      blocks: [
        {
          id: "pg-agn-nav",
          type: "navbar",
          props: {
            brand: "CYBER // LAB",
            links: "Projects|/projects\nCapabilities|/services\nVentures|/ventures\nAbout|/about",
            phone: "Book Call",
            phoneHref: "/contact",
            overlay: false,
          },
        },
        {
          id: "pg-agn-hero",
          type: "coverhero",
          props: {
            crumb: "",
            eyebrow: "● ACCEPTING SELECT PROJECTS",
            heading: "We build unforgettable digital experiences that define categories.",
            sub: "Specialized design engineering studio partnering with venture-backed founders and forward-looking brands.",
            cta: "Explore Our Work",
            ctaHref: "/projects",
            cta2: "Our Capabilities",
            cta2Href: "/services",
            size: "screen",
          },
        },
        {
          id: "pg-agn-brands",
          type: "brandstrip",
          props: {
            label: "Selected Collaborators & Backers",
            items: "Paradigm\nSolana Labs\nFramework Ventures\nNeon Global\nPolymarket",
          },
        },
        {
          id: "pg-agn-panel",
          type: "panel",
          props: {
            mode: "rows",
            eyebrow: "Capabilities",
            heading: "Full-stack brand and product engineering.",
            body: "From zero-to-one design systems to high-performance WebGL web applications, we ship polished craft at startup speed.",
            checks: "Product Design (UI/UX)\nBrand Strategy & Identity\nWebGL & Interactive 3D\nNext.js & React Engineering",
            actions: "Review Service Tiers|/services",
            items: "Zero-to-One Product Launch|Strategy, rapid prototyping and production launch.|/launch\nBrand Transformation|Complete visual identity, type design and guidelines.|/brand\nEnterprise Web Platforms|Headless CMS, high-velocity design systems and edge delivery.|/platforms",
            itemStyle: "feature",
            flip: false,
            kicker: "",
            box: true,
            tone: "muted",
          },
        },
        {
          id: "pg-agn-cta",
          type: "cta",
          props: {
            heading: "Have an ambitious project in mind? Let's make it real.",
            cta: "Initiate Project Discussion",
            ctaHref: "/contact",
          },
        },
        {
          id: "pg-agn-footer",
          type: "sitefooter",
          props: {
            brand: "CYBER LAB GLOBAL",
            tagline: "Engineered with obsession in Berlin, San Francisco, and Singapore.",
            colATitle: "Studio",
            colALinks: "Case Studies|/projects\nEthos|/about\nManifesto|/manifesto",
            colBTitle: "Network",
            colBLinks: "GitHub|https://github.com\nX / Twitter|https://x.com\nDiscord|https://discord.com",
            contactTitle: "Inquiries",
            phone: "",
            email: "hello@cyberlab.global",
            address: "Berlin · SF · Singapore",
            copyright: "© 2026 Cyber Lab Inc. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "pg-artisan-boutique",
    name: "Artisan Boutique & Hospitality",
    category: "page",
    archetype: "artisan",
    archetypeLabel: ARCHETYPES.artisan.label,
    tagline: "Warm boutique lifestyle, culinary, and crafted services page",
    description:
      "Comforting linen tones, split narrative hero, curated service packages with pricing indicators, customer endorsements, and reservation details.",
    tags: ["Artisan", "Warm", "Hospitality", "Culinary", "Craft"],
    tokens: ARCHETYPES.artisan.tokens,
    layout: {
      version: 1,
      blocks: [
        {
          id: "pg-art-nav",
          type: "navbar",
          props: {
            brand: "Tuscan Roasters",
            links: "Single Origin|/coffees\nHeritage|/heritage\nSubstack|/journal\nVisit Us|/locations",
            phone: "Reserve Table",
            phoneHref: "/reserve",
            overlay: false,
          },
        },
        {
          id: "pg-art-hero",
          type: "hero",
          props: {
            heading: "Crafted by hand, roasted with patience over olive wood.",
            sub: "Direct trade coffees sourced from sustainable smallholder family farms and roasted weekly in our historic Florence atelier.",
            cta: "Explore Reserve Harvests",
            ctaHref: "/coffees",
          },
        },
        {
          id: "pg-art-split",
          type: "split",
          props: {
            eyebrow: "Our Heritage",
            heading: "Four generations of slow flame craftsmanship.",
            body: "We believe true espresso cannot be rushed by automated computers. Every harvest batch is smelled, monitored and roasted by eye.",
            facts: "1924|Year founded\n18|Single-origin family partners\n100%|Solar powered roastery",
            cta: "Read Farm Stories",
            ctaHref: "/heritage",
            imageAlt: "Florence olive wood coffee roastery workshop",
            side: "left",
            tone: "light",
            checks: "Sustainably certified compostable packaging\nFair wages exceeding fair-trade baseline by 40%",
          },
        },
        {
          id: "pg-art-contact",
          type: "contact",
          props: {
            heading: "Visit Our Florence Tasting Room",
            intro: "Open daily for coffee tastings, pastry pairings, and bean consultations.",
            address: "Via delle Belle Donne, 12R, 50123 Firenze FI, Italy",
            hours: "Mon – Sat: 07:30 – 18:00\nSunday: 08:30 – 16:00",
            email: "ciao@tuscanroasters.com",
            phone: "+39 055 210874",
          },
        },
        {
          id: "pg-art-footer",
          type: "sitefooter",
          props: {
            brand: "Tuscan Artisan Roasters",
            tagline: "Roasted with pride in Florence, shipped fresh worldwide.",
            colATitle: "Harvests",
            colALinks: "Single Origin|/coffees\nHeritage|/heritage\nJournal|/journal",
            colBTitle: "Atelier",
            colBLinks: "Florence Tasting Room|/locations\nBrewing Masterclasses|/classes\nPrivate Tasting|/private",
            contactTitle: "Atelier Details",
            phone: "+39 055 210874",
            email: "ciao@tuscanroasters.com",
            address: "Via delle Belle Donne 12R, 50123 Firenze, Italy",
            copyright: "© 2026 Tuscan Roasters S.r.l. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "pg-vanguard-corp",
    name: "Enterprise Advisory Firm",
    category: "page",
    archetype: "corporate",
    archetypeLabel: ARCHETYPES.corporate.label,
    tagline: "Credibility-focused B2B corporate and financial advisory layout",
    description:
      "Structured trust statistics, regulatory governance pillars, executive service breakdown, client verification, and private consultation scheduler.",
    tags: ["Corporate", "Enterprise", "Consulting", "Finance", "B2B"],
    tokens: ARCHETYPES.corporate.tokens,
    layout: {
      version: 1,
      blocks: [
        {
          id: "pg-corp-nav",
          type: "navbar",
          props: {
            brand: "Vanguard Advisory Group",
            links: "Advisory|/advisory\nPractices|/practices\nInsights|/insights\nLeadership|/leadership",
            phone: "Client Portal",
            phoneHref: "/portal",
            overlay: false,
          },
        },
        {
          id: "pg-corp-hero",
          type: "coverhero",
          props: {
            crumb: "",
            eyebrow: "GLOBAL STRATEGIC ADVISORY",
            heading: "Navigating complex market transitions with institutional rigor.",
            sub: "We partner with multinational enterprise executives, board directors, and asset managers to protect capital and accelerate transformation.",
            cta: "Request Consultation",
            ctaHref: "/consultation",
            cta2: "View Practice Areas",
            cta2Href: "/practices",
            size: "screen",
          },
        },
        {
          id: "pg-corp-values",
          type: "values",
          props: {
            eyebrow: "Institutional Trust",
            heading: "Proven Experience at Global Scale",
            items:
              "$12B+|Cross-Border Transactions Advised\n99.8%|Regulatory Compliance Clearance Rate\n42|Fortune 500 Enterprise Engagements\n18|Global Financial Center Hubs",
            cols: 4,
            tone: "light",
          },
        },
        {
          id: "pg-corp-catalog",
          type: "catalog",
          props: {
            eyebrow: "Specialized Practices",
            heading: "Comprehensive Strategic Capabilities",
            intro: "Integrated multidisciplinary teams structured around your high-stakes initiatives.",
            items:
              "Corporate Mergers & Acquisitions|Due diligence, synergy modeling and integration.|Sheen: Tier 1; Best for: Global Boards|/m-and-a\nRegulatory & Risk Governance|ESG, cross-border tariff and anti-trust navigation.|Sheen: Critical; Best for: Regulated Sectors|/risk\nDigital Infrastructure Transformation|Enterprise ERP modernization and AI enablement.|Sheen: Strategic; Best for: High Growth|/digital",
            cols: 3,
            tone: "light",
            numbered: true,
          },
        },
        {
          id: "pg-corp-cta",
          type: "cta",
          props: {
            heading: "Arrange a confidential executive briefing with our managing partners.",
            cta: "Schedule Executive Briefing",
            ctaHref: "/briefing",
          },
        },
        {
          id: "pg-corp-footer",
          type: "sitefooter",
          props: {
            brand: "Vanguard Advisory Group Global Ltd.",
            tagline: "Trusted advisors to corporate leadership since 1998.",
            colATitle: "Practices",
            colALinks: "Mergers & Acquisitions|/m-and-a\nCapital Markets|/capital\nRisk & Governance|/risk",
            colBTitle: "Governance",
            colBLinks: "Global Compliance|/compliance\nCode of Conduct|/conduct\nPrivacy Policy|/privacy",
            contactTitle: "Global Headquarters",
            phone: "+1 (212) 555-0100",
            email: "inquiries@vanguardadvisory.com",
            address: "Offices: New York · London · Zurich · Singapore",
            copyright: "© 2026 Vanguard Advisory Group. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },

  /* =====================================================================
   * CATEGORY 3: HEADER TEMPLATES (5 Variants)
   * ===================================================================== */
  {
    id: "hd-saas-sticky",
    name: "SaaS Translucent Sticky Header",
    category: "header",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "Modern floating navbar with category links and trial button",
    description:
      "Sticky blurred bar with clean brand badge, categorized links (Features, Solutions, Pricing, Docs), and solid primary 'Start Free' CTA button.",
    tags: ["Header", "Navbar", "SaaS", "Sticky"],
    tokens: ARCHETYPES.saas.tokens,
    block: {
      id: "hd-saas-navbar",
      type: "navbar",
      props: {
        brand: "Apex Cloud",
        links: "Features|#features\nSolutions|#solutions\nPricing|#pricing\nDocs|#docs",
        phone: "Start Free",
        phoneHref: "/signup",
        overlay: false,
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "hd-saas-navbar",
          type: "navbar",
          props: {
            brand: "Apex Cloud",
            links: "Features|#features\nSolutions|#solutions\nPricing|#pricing\nDocs|#docs",
            phone: "Start Free",
            phoneHref: "/signup",
            overlay: false,
          },
        },
      ],
    },
  },
  {
    id: "hd-minimal-studio",
    name: "Minimalist Editorial Header",
    category: "header",
    archetype: "minimal",
    archetypeLabel: ARCHETYPES.minimal.label,
    tagline: "Refined typographic header with wide letter-spacing",
    description:
      "Understated editorial header with serif brand mark, spaced uppercase navigation (WORK, ABOUT, JOURNAL, CONTACT), and hairline border.",
    tags: ["Header", "Minimal", "Editorial", "Typography"],
    tokens: ARCHETYPES.minimal.tokens,
    block: {
      id: "hd-min-navbar",
      type: "navbar",
      props: {
        brand: "ATELIER MONO",
        links: "WORKS|/works\nSTUDIO|/studio\nJOURNAL|/journal\nCONTACT|/contact",
        phone: "",
        phoneHref: "",
        overlay: false,
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "hd-min-navbar",
          type: "navbar",
          props: {
            brand: "ATELIER MONO",
            links: "WORKS|/works\nSTUDIO|/studio\nJOURNAL|/journal\nCONTACT|/contact",
            phone: "",
            phoneHref: "",
            overlay: false,
          },
        },
      ],
    },
  },
  {
    id: "hd-agency-dark",
    name: "Bold Agency Split Header",
    category: "header",
    archetype: "agency",
    archetypeLabel: ARCHETYPES.agency.label,
    tagline: "High-contrast dark header with neon status pill badge",
    description:
      "Deep obsidian background with bright lime brandmark, bold navigation links, and high-visibility pill 'Book Call' action.",
    tags: ["Header", "Dark Mode", "Neon", "Agency"],
    tokens: ARCHETYPES.agency.tokens,
    block: {
      id: "hd-agn-navbar",
      type: "navbar",
      props: {
        brand: "CYBER // LAB",
        links: "Work|/work\nServices|/services\nVentures|/ventures\nAbout|/about",
        phone: "Book Call",
        phoneHref: "/contact",
        overlay: false,
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "hd-agn-navbar",
          type: "navbar",
          props: {
            brand: "CYBER // LAB",
            links: "Work|/work\nServices|/services\nVentures|/ventures\nAbout|/about",
            phone: "Book Call",
            phoneHref: "/contact",
            overlay: false,
          },
        },
      ],
    },
  },
  {
    id: "hd-artisan-boutique",
    name: "Artisan Boutique & Store Header",
    category: "header",
    archetype: "artisan",
    archetypeLabel: ARCHETYPES.artisan.label,
    tagline: "Two-tier header with promotional banner and luxury wordmark",
    description:
      "Warm cream tones, centered luxury wordmark, balanced left-right menu links, and customer reservation action.",
    tags: ["Header", "Artisan", "Warm", "Boutique"],
    tokens: ARCHETYPES.artisan.tokens,
    block: {
      id: "hd-art-navbar",
      type: "navbar",
      props: {
        brand: "Tuscan Roasters",
        links: "Harvests|/coffees\nHeritage|/heritage\nJournal|/journal\nAtelier|/atelier",
        phone: "Reserve Table",
        phoneHref: "/reserve",
        overlay: false,
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "hd-art-navbar",
          type: "navbar",
          props: {
            brand: "Tuscan Roasters",
            links: "Harvests|/coffees\nHeritage|/heritage\nJournal|/journal\nAtelier|/atelier",
            phone: "Reserve Table",
            phoneHref: "/reserve",
            overlay: false,
          },
        },
      ],
    },
  },
  {
    id: "hd-corporate-utility",
    name: "Corporate Enterprise Utility Header",
    category: "header",
    archetype: "corporate",
    archetypeLabel: ARCHETYPES.corporate.label,
    tagline: "Corporate header with institutional portal login and hotline",
    description:
      "Structured enterprise navbar featuring client portal authentication shortcut, phone hotline, and structured service practices.",
    tags: ["Header", "Corporate", "Enterprise", "Utility"],
    tokens: ARCHETYPES.corporate.tokens,
    block: {
      id: "hd-corp-navbar",
      type: "navbar",
      props: {
        brand: "Vanguard Advisory",
        links: "Practices|/practices\nSectors|/sectors\nInsights|/insights\nLeadership|/leadership",
        phone: "+1 (800) 555-0199",
        phoneHref: "tel:+18005550199",
        overlay: false,
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "hd-corp-navbar",
          type: "navbar",
          props: {
            brand: "Vanguard Advisory",
            links: "Practices|/practices\nSectors|/sectors\nInsights|/insights\nLeadership|/leadership",
            phone: "+1 (800) 555-0199",
            phoneHref: "tel:+18005550199",
            overlay: false,
          },
        },
      ],
    },
  },

  /* =====================================================================
   * CATEGORY 4: FOOTER TEMPLATES (5 Variants)
   * ===================================================================== */
  {
    id: "ft-saas-multi",
    name: "SaaS 4-Column Navigation Footer",
    category: "footer",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "Multi-column directory with product links, legal and copyright",
    description:
      "Complete SaaS footer structure with company elevator pitch, grouped product links, documentation, and copyright status.",
    tags: ["Footer", "SaaS", "Multi-column", "Directory"],
    tokens: ARCHETYPES.saas.tokens,
    block: {
      id: "ft-saas-sitefooter",
      type: "sitefooter",
      props: {
        brand: "Apex Cloud, Inc.",
        tagline: "Intelligent developer telemetry, distributed pipelines, and real-time observability.",
        colATitle: "Platform",
        colALinks: "Pipelines|/pipelines\nConnectors|/connectors\nSecurity|/security\nPricing|/pricing",
        colBTitle: "Resources",
        colBLinks: "Documentation|/docs\nAPI Reference|/api\nChangelog|/changelog\nSystem Status|/status",
        contactTitle: "Support",
        phone: "",
        email: "support@apexcloud.dev",
        address: "",
        copyright: "© 2026 Apex Cloud Inc. All rights reserved.",
        note: "",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "ft-saas-sitefooter",
          type: "sitefooter",
          props: {
            brand: "Apex Cloud, Inc.",
            tagline: "Intelligent developer telemetry, distributed pipelines, and real-time observability.",
            colATitle: "Platform",
            colALinks: "Pipelines|/pipelines\nConnectors|/connectors\nSecurity|/security\nPricing|/pricing",
            colBTitle: "Resources",
            colBLinks: "Documentation|/docs\nAPI Reference|/api\nChangelog|/changelog\nSystem Status|/status",
            contactTitle: "Support",
            phone: "",
            email: "support@apexcloud.dev",
            address: "",
            copyright: "© 2026 Apex Cloud Inc. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "ft-minimal-row",
    name: "Minimalist Single-Row Studio Footer",
    category: "footer",
    archetype: "minimal",
    archetypeLabel: ARCHETYPES.minimal.label,
    tagline: "Understated horizontal footer with live timezone indicators",
    description:
      "Ultra-clean horizontal design with studio locations, local time stamps, quiet contact details, and registered entity notice.",
    tags: ["Footer", "Minimal", "Single-row", "Studio"],
    tokens: ARCHETYPES.minimal.tokens,
    block: {
      id: "ft-min-sitefooter",
      type: "sitefooter",
      props: {
        brand: "ATELIER MONO",
        tagline: "London 16:30 GMT · Tokyo 01:30 JST",
        colATitle: "",
        colALinks: "",
        colBTitle: "",
        colBLinks: "",
        contactTitle: "Inquiries",
        phone: "",
        email: "contact@ateliermono.design",
        address: "14 Berwick Street, Soho, London W1F 0PP",
        copyright: "© 2026 Atelier Mono Ltd. Registered in England & Wales.",
        note: "",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "ft-min-sitefooter",
          type: "sitefooter",
          props: {
            brand: "ATELIER MONO",
            tagline: "London 16:30 GMT · Tokyo 01:30 JST",
            colATitle: "",
            colALinks: "",
            colBTitle: "",
            colBLinks: "",
            contactTitle: "Inquiries",
            phone: "",
            email: "contact@ateliermono.design",
            address: "14 Berwick Street, Soho, London W1F 0PP",
            copyright: "© 2026 Atelier Mono Ltd. Registered in England & Wales.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "ft-agency-dark",
    name: "Bold Dark Studio Callout Footer",
    category: "footer",
    archetype: "agency",
    archetypeLabel: ARCHETYPES.agency.label,
    tagline: "High-contrast agency footer with prominent project callout",
    description:
      "Dark obsidian theme with neon accents, oversized inquiry prompt, developer network links, and copyright statement.",
    tags: ["Footer", "Dark Mode", "Neon", "Agency"],
    tokens: ARCHETYPES.agency.tokens,
    block: {
      id: "ft-agn-sitefooter",
      type: "sitefooter",
      props: {
        brand: "CYBER LAB GLOBAL",
        tagline: "Have an ambitious project in mind? We respond within 24 hours.",
        colATitle: "Explore",
        colALinks: "Selected Works|/work\nCapabilities|/services\nVentures Lab|/ventures",
        colBTitle: "Connect",
        colBLinks: "X / Twitter|https://x.com\nGitHub|https://github.com\nReadCV|https://read.cv",
        contactTitle: "Direct Line",
        phone: "",
        email: "hello@cyberlab.global",
        address: "",
        copyright: "© 2026 Cyber Lab Global. Built with RK React Builder.",
        note: "",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "ft-agn-sitefooter",
          type: "sitefooter",
          props: {
            brand: "CYBER LAB GLOBAL",
            tagline: "Have an ambitious project in mind? We respond within 24 hours.",
            colATitle: "Explore",
            colALinks: "Selected Works|/work\nCapabilities|/services\nVentures Lab|/ventures",
            colBTitle: "Connect",
            colBLinks: "X / Twitter|https://x.com\nGitHub|https://github.com\nReadCV|https://read.cv",
            contactTitle: "Direct Line",
            phone: "",
            email: "hello@cyberlab.global",
            address: "",
            copyright: "© 2026 Cyber Lab Global. Built with RK React Builder.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "ft-artisan-newsletter",
    name: "Artisan Boutique & Atelier Footer",
    category: "footer",
    archetype: "artisan",
    archetypeLabel: ARCHETYPES.artisan.label,
    tagline: "Warm boutique footer with newsletter invite and studio hours",
    description:
      "Terracotta and linen styling with physical atelier opening hours, contact details, and heritage commitment.",
    tags: ["Footer", "Artisan", "Warm", "Hospitality"],
    tokens: ARCHETYPES.artisan.tokens,
    block: {
      id: "ft-art-sitefooter",
      type: "sitefooter",
      props: {
        brand: "Tuscan Roasters S.r.l.",
        tagline: "Wood-fired single-origin roastery based in Florence, Italy.",
        colATitle: "Curations",
        colALinks: "Single Origins|/coffees\nEspresso Blends|/blends\nBrew Equipment|/equipment",
        colBTitle: "Stories",
        colBLinks: "Our Farmers|/farms\nBrewing Guides|/guides\nPhilosophy|/about",
        contactTitle: "Florence Atelier",
        address: "Via delle Belle Donne 12R, 50123 Firenze, Italy",
        phone: "+39 055 210874",
        email: "atelier@tuscanroasters.com",
        copyright: "© 2026 Tuscan Roasters S.r.l. All rights reserved.",
        note: "",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "ft-art-sitefooter",
          type: "sitefooter",
          props: {
            brand: "Tuscan Roasters S.r.l.",
            tagline: "Wood-fired single-origin roastery based in Florence, Italy.",
            colATitle: "Curations",
            colALinks: "Single Origins|/coffees\nEspresso Blends|/blends\nBrew Equipment|/equipment",
            colBTitle: "Stories",
            colBLinks: "Our Farmers|/farms\nBrewing Guides|/guides\nPhilosophy|/about",
            contactTitle: "Florence Atelier",
            address: "Via delle Belle Donne 12R, 50123 Firenze, Italy",
            phone: "+39 055 210874",
            email: "atelier@tuscanroasters.com",
            copyright: "© 2026 Tuscan Roasters S.r.l. All rights reserved.",
            note: "",
          },
        },
      ],
    },
  },
  {
    id: "ft-corporate-global",
    name: "Corporate Multi-Office Directory Footer",
    category: "footer",
    archetype: "corporate",
    archetypeLabel: ARCHETYPES.corporate.label,
    tagline: "Multi-office enterprise directory with compliance disclosures",
    description:
      "Global hub addresses (New York, London, Zurich, Singapore), corporate governance links, disclaimer notice, and copyright.",
    tags: ["Footer", "Corporate", "Enterprise", "Compliance"],
    tokens: ARCHETYPES.corporate.tokens,
    block: {
      id: "ft-corp-sitefooter",
      type: "sitefooter",
      props: {
        brand: "Vanguard Advisory Group",
        tagline: "Strategic advisory, regulatory governance, and transformation counsel.",
        colATitle: "Practices",
        colALinks: "Capital Markets|/capital\nRestructuring|/restructuring\nRisk Management|/risk",
        colBTitle: "Governance",
        colBLinks: "Regulatory Disclosures|/disclosures\nEthics Hotline|/ethics\nTerms of Service|/terms",
        contactTitle: "Global Headquarters",
        address: "280 Park Avenue, 32nd Floor, New York, NY 10017",
        phone: "+1 (212) 555-0100",
        email: "inquiries@vanguardadvisory.com",
        copyright: "© 2026 Vanguard Advisory Group Global Ltd.",
        note: "",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "ft-corp-sitefooter",
          type: "sitefooter",
          props: {
            brand: "Vanguard Advisory Group",
            tagline: "Strategic advisory, regulatory governance, and transformation counsel.",
            colATitle: "Practices",
            colALinks: "Capital Markets|/capital\nRestructuring|/restructuring\nRisk Management|/risk",
            colBTitle: "Governance",
            colBLinks: "Regulatory Disclosures|/disclosures\nEthics Hotline|/ethics\nTerms of Service|/terms",
            contactTitle: "Global Headquarters",
            address: "280 Park Avenue, 32nd Floor, New York, NY 10017",
            phone: "+1 (212) 555-0100",
            email: "inquiries@vanguardadvisory.com",
            copyright: "© 2026 Vanguard Advisory Group Global Ltd.",
            note: "",
          },
        },
      ],
    },
  },

  /* =====================================================================
   * CATEGORY 5: SECTION TEMPLATES (5 Variants)
   * ===================================================================== */
  {
    id: "sc-hero-metrics",
    name: "SaaS Cover Hero with Live Metrics",
    category: "section",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "High-impact hero block with version badge and dual action buttons",
    description:
      "Ideal top-of-page hero section featuring badge announcement, bold headline, dual CTAs, and clear value proposition.",
    tags: ["Section", "Hero", "SaaS", "Conversion"],
    tokens: ARCHETYPES.saas.tokens,
    block: {
      id: "sc-hero-cover",
      type: "coverhero",
      props: {
        crumb: "",
        eyebrow: "v2.8 RELEASED · ENTERPRISE TELEMETRY",
        heading: "Scale your cloud operations without the engineering drag",
        sub: "The all-in-one developer platform for building, deploying, and observing resilient microservices.",
        cta: "Deploy for Free",
        ctaHref: "/signup",
        cta2: "View Interactive Demo",
        cta2Href: "/demo",
        size: "screen",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "sc-hero-cover",
          type: "coverhero",
          props: {
            crumb: "",
            eyebrow: "v2.8 RELEASED · ENTERPRISE TELEMETRY",
            heading: "Scale your cloud operations without the engineering drag",
            sub: "The all-in-one developer platform for building, deploying, and observing resilient microservices.",
            cta: "Deploy for Free",
            ctaHref: "/signup",
            cta2: "View Interactive Demo",
            cta2Href: "/demo",
            size: "screen",
          },
        },
      ],
    },
  },
  {
    id: "sc-feature-grid",
    name: "Modern 3-Column Service & Feature Grid",
    category: "section",
    archetype: "saas",
    archetypeLabel: ARCHETYPES.saas.label,
    tagline: "Section block paired with a clean 3-column services grid",
    description:
      "Structured introductory section with eyebrow, headline, concise body text, and a 3-column service grid showcasing capabilities.",
    tags: ["Section", "Features", "Services", "Grid"],
    tokens: ARCHETYPES.saas.tokens,
    block: {
      id: "sc-feat-section",
      type: "section",
      props: {
        eyebrow: "What We Deliver",
        heading: "Engineered for speed, built for reliability",
        body: "Every layer of our software architecture is optimized to deliver consistent sub-50ms response times globally.",
        linkLabel: "View all technical specifications",
        linkHref: "/specs",
        tone: "light",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "sc-feat-section",
          type: "section",
          props: {
            eyebrow: "What We Deliver",
            heading: "Engineered for speed, built for reliability",
            body: "Every layer of our software architecture is optimized to deliver consistent sub-50ms response times globally.",
            linkLabel: "View all technical specifications",
            linkHref: "/specs",
            tone: "light",
          },
        },
        {
          id: "sc-feat-grid",
          type: "services",
          props: {
            title: "Core Service Modules",
            source: "service",
            limit: 3,
            cols: 3,
            category: "",
            orderBy: "menu_order",
            order: "asc",
          },
        },
      ],
    },
  },
  {
    id: "sc-social-proof",
    name: "Social Proof & Verified Testimonials",
    category: "section",
    archetype: "minimal",
    archetypeLabel: ARCHETYPES.minimal.label,
    tagline: "Authentic testimonial quotes that build immediate trust",
    description:
      "Clean quote component highlighting client validation, executive title, company affiliation, and editorial styling.",
    tags: ["Section", "Testimonial", "Reviews", "Social Proof"],
    tokens: ARCHETYPES.minimal.tokens,
    block: {
      id: "sc-proof-testimonial",
      type: "testimonial",
      props: {
        quote:
          "Working with this team felt like unlocking a decade of institutional design thinking in under six weeks. Truly exceptional craft.",
        author: "Marcus Lindqvist",
        role: "Design Director at Stockholm Modern",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "sc-proof-testimonial",
          type: "testimonial",
          props: {
            quote:
              "Working with this team felt like unlocking a decade of institutional design thinking in under six weeks. Truly exceptional craft.",
            author: "Marcus Lindqvist",
            role: "Design Director at Stockholm Modern",
          },
        },
      ],
    },
  },
  {
    id: "sc-pricing-cta",
    name: "High-Converting Trial & CTA Band",
    category: "section",
    archetype: "agency",
    archetypeLabel: ARCHETYPES.agency.label,
    tagline: "Contrasting callout band with action button and trust factors",
    description:
      "Bold CTA block designed to drive conversions with action button and zero-friction guarantee.",
    tags: ["Section", "CTA", "Callout", "Conversion"],
    tokens: ARCHETYPES.agency.tokens,
    block: {
      id: "sc-cta-band",
      type: "cta",
      props: {
        heading: "Ready to launch your next project? Get started with zero setup fees.",
        cta: "Claim Your 14-Day Free Trial",
        ctaHref: "/get-started",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "sc-cta-band",
          type: "cta",
          props: {
            heading: "Ready to launch your next project? Get started with zero setup fees.",
            cta: "Claim Your 14-Day Free Trial",
            ctaHref: "/get-started",
          },
        },
      ],
    },
  },
  {
    id: "sc-values-split",
    name: "Brand Story & Core Values Split",
    category: "section",
    archetype: "artisan",
    archetypeLabel: ARCHETYPES.artisan.label,
    tagline: "50/50 split layout with narrative storytelling and principle badges",
    description:
      "Editorial split layout with left-hand visual balance, brand narrative, checklist validation, and stat badges.",
    tags: ["Section", "Story", "Values", "Split"],
    tokens: ARCHETYPES.artisan.tokens,
    block: {
      id: "sc-val-split",
      type: "split",
      props: {
        eyebrow: "Our Heritage & Craft",
        heading: "Built slowly, guided by principles, built to endure.",
        body: "We reject the disposable trends of fast-cycle manufacturing. Every piece in our workshop is shaped from certified sustainable European hardwoods.",
        facts: "100%|Solid French Oak\n25 Yr|Structural Warranty\n0%|Synthetic Adhesives",
        cta: "Explore Our Workshop",
        ctaHref: "/workshop",
        imageAlt: "Artisan woodwork studio craftsmanship",
        side: "right",
        tone: "light",
        checks: "Hand-finished with cold-pressed natural linseed oils\nSourced exclusively from PEFC-certified managed forests",
      },
    },
    layout: {
      version: 1,
      blocks: [
        {
          id: "sc-val-split",
          type: "split",
          props: {
            eyebrow: "Our Heritage & Craft",
            heading: "Built slowly, guided by principles, built to endure.",
            body: "We reject the disposable trends of fast-cycle manufacturing. Every piece in our workshop is shaped from certified sustainable European hardwoods.",
            facts: "100%|Solid French Oak\n25 Yr|Structural Warranty\n0%|Synthetic Adhesives",
            cta: "Explore Our Workshop",
            ctaHref: "/workshop",
            imageAlt: "Artisan woodwork studio craftsmanship",
            side: "right",
            tone: "light",
            checks: "Hand-finished with cold-pressed natural linseed oils\nSourced exclusively from PEFC-certified managed forests",
          },
        },
      ],
    },
  },
];
