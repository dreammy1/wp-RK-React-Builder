# RK React Builder — Design Direction

## Three initial directions

### Theme Name: Print Studio
Very Brief Intro: A warm editorial workbench inspired by Swiss print production, with paper surfaces, graphite controls, and one sharp action color. The builder should feel measured, tactile, and crafted.
Probability: 0.07

### Theme Name: Night Flight
Very Brief Intro: A dark, technical command center with cool steel surfaces and bright signal colors, designed for fast-moving production teams. Dense but legible, with a stronger operations feel.
Probability: 0.03

### Theme Name: Soft Systems
Very Brief Intro: A quiet, airy interface using pale mineral tones, generous whitespace, and soft modular panels. The emotional intent is approachable clarity for non-technical editors.
Probability: 0.09

## Selected approach: Print Studio

### Design Movement
Contemporary Swiss editorial design translated into a digital design tool: strict typographic hierarchy, visible alignment logic, small annotations, and confident asymmetry.

### Core Principles
1. Make structure visible: use labels, guides, measurements, and status marks to reveal how a page is assembled.
2. Use contrast with restraint: keep most surfaces warm and quiet, reserving electric lime for actions, selection, and success states.
3. Favor useful asymmetry: compose the editor around a persistent palette, a central canvas, and an inspector rather than a centered card stack.
4. Treat every state as designed: empty, loading, saved, error, preview, and selected states all get specific visual language.

### Color Philosophy
The base is warm ivory rather than sterile white, giving the canvas the feel of archival paper. Graphite and ink navy create trustworthy working contrast. Electric lime is the owned brand signal: it reads as a registration mark, focus ring, and “this is live” indicator without turning the product into a neon interface.

### Layout Paradigm
A three-zone production desk: palette rail on the left, page canvas in the middle, inspector rail on the right. Public preview expands the canvas but retains a slim utility bar. Content sections use offset columns, ruled separators, and editorial measure rather than uniform centered cards.

### Signature Elements
- Fine registration lines and measurement ticks around the canvas.
- Small uppercase specimen labels with monospaced metadata.
- Electric-lime selection brackets and “LIVE” status markers.

### Interaction Philosophy
Interactions should feel direct and reversible. Dragging a block leaves a clear insertion rule; selecting a block exposes its editing surface without hiding context; destructive actions require a clear icon and preserve an undo path where possible. Keyboard focus is visibly marked with the lime signal color.

### Animation
Use short, decisive transitions under 240ms with an editorial snap: panel reveals fade and translate 6px, selected block chrome appears with a 120ms opacity/transform transition, and save status moves from “Saving” to “Saved” without bouncing. Drag insertion rules fade in immediately. Respect reduced motion and never animate layout dimensions.

### Typography System
Use Space Grotesk for interface and display headings, paired with IBM Plex Mono for labels, coordinates, statuses, and JSON. Display headings use tight tracking and occasional italic emphasis; body copy stays readable at 15–17px with a 1.6 line-height. Avoid Inter.

### Brand Essence
RK React Builder is a focused visual page-building workbench for teams turning WordPress content into fast React sites, differentiated by a shared block system that keeps editing and rendering aligned. Personality: **precise, candid, energetic**.

### Brand Voice
Headlines are compact and declarative. CTAs describe the real action. Microcopy explains the system without overselling it.

Example lines:
- “Assemble the page. Keep the content live.”
- “Saved to WordPress · the public renderer will pick this up.”

### Wordmark & Logo
The mark is a pair of interlocking angular brackets that imply both “RK” and an open component boundary. It appears as a solid graphite symbol with a single electric-lime registration cut, never as a default text logo.

### Signature Brand Color
**Registration Lime — #C7F36B**. It is bright enough to signal interaction on graphite, but warm enough to belong to the paper-and-ink system.

## Style Decisions

- Use warm ivory and graphite as the primary surfaces; avoid purple gradients and generic rounded dashboard cards.
- Keep the three-zone editor structure persistent on desktop and collapse it intentionally on mobile.
- Use generated assets only where they clarify the demo canvas; do not repeat a single image across unrelated content.
- Keep WordPress connectivity explicit through a configurable REST base URL and a local demo mode.
