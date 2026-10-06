# Design System — "Quiet Luxury"

## 1. Reference research (patterns, not content)

Studied categories: luxury hospitality and wellness (Aman, Six Senses, Rosewood), private wealth and family-office advisory (Stonehage Fleming, Rothschild & Co private wealth), luxury editorial (Kinfolk, Cereal), and high-end personal brands (Byredo, Loro Piana). Observed patterns we adopt — expressed in our own visual language:

| Pattern | Why it works for this audience | Our application |
| --- | --- | --- |
| Very large serif display type with tight leading, set against generous negative space | Signals confidence; nothing is "selling" | Cormorant Garamond display sizes up to 7.5rem, max 3 lines |
| Small uppercase "eyebrow" labels with wide tracking | Editorial wayfinding without heavy UI | `Eyebrow` component, 0.68rem, 0.32em tracking, champagne |
| Hairline rules instead of cards | Reads as print, not SaaS | 1px `line` tokens at 8–16% opacity; cards avoided |
| Single, understated CTA per viewport | Respects attention; implies selectivity | Text-link CTAs with animated rule; one solid CTA (Apply) |
| Numbered sections (I, II, III…) | Conveys method and rigour | Roman numerals on disciplines and methodology |
| Slow, short-distance reveal animations | Calm, premium | 700–900ms, 16–24px travel, `prefers-reduced-motion` respected |
| Photography framed, not full-bleed stock | Personal practice, authentic | Arched "temple" frames (Vedic reference) with champagne hairline; duotone on session photos |
| Discreet trust signalling | Privacy is the luxury | "NDA Protected & Encrypted Inquiry Flow" seal, no badges/popups |

Avoided: discount badges, countdowns, chat widgets, stock "wellness" imagery, saturated gradients, card grids with icons, aggressive "Buy now".

## 2. Tokens

Defined once in `frontend/src/styles/tokens.css` as CSS custom properties and exposed to Tailwind via `@theme`.

### Colour

| Token | Hex | Use |
| --- | --- | --- |
| `midnight-950` | `#070B14` | Page background (deepest) |
| `midnight-900` | `#0B1220` | Primary background |
| `midnight-800` | `#111A2C` | Raised surfaces |
| `charcoal-800` | `#1A1D23` | Alternate section background |
| `slate-600` | `#3A4556` | Muted surfaces, borders on hover |
| `slate-400` | `#8A94A6` | Secondary text on dark |
| `emerald-700` | `#0F4C3A` | Accent fields, focus halos |
| `emerald-500` | `#1F7A5C` | Accent interactive |
| `emerald-300` | `#7FB8A0` | Accent text on dark |
| `champagne-400` | `#C9B07A` | Gold detailing, rules, eyebrow |
| `champagne-200` | `#E6D7B0` | Hover/active gold |
| `ivory-50` | `#F7F3EA` | Primary text on dark |
| `ivory-200` | `#E9E2D3` | Body text on dark |

Contrast (WCAG 2.2 AA): ivory-50 on midnight-900 ≈ 16.6:1; ivory-200 ≈ 14:1; slate-400 ≈ 6.4:1; champagne-400 ≈ 9:1. emerald-300 ≈ 8.3:1.

### Typography

| Role | Family | Size (clamp) | Leading | Tracking |
| --- | --- | --- | --- | --- |
| Display | Cormorant Garamond 300/400 italic | 3rem → 7.5rem | 0.95 | -0.02em |
| H1 | Cormorant Garamond 400 | 2.5rem → 4.5rem | 1.02 | -0.015em |
| H2 | Cormorant Garamond 400 | 2rem → 3.25rem | 1.08 | -0.01em |
| H3 | Cormorant Garamond 500 | 1.5rem → 2rem | 1.15 | 0 |
| Body L | Manrope 300 | 1.125rem → 1.25rem | 1.7 | 0 |
| Body | Manrope 400 | 1rem | 1.7 | 0 |
| Eyebrow | Manrope 500 uppercase | 0.68rem | 1 | 0.32em |

Fonts are self-hosted (`@fontsource`) — no third-party font requests, simpler CSP, better privacy.

### Spacing & layout

* 4px base; section rhythm `py-28 md:py-40`.
* Container: `max-w-[1320px]`, gutters `px-6 md:px-10 lg:px-16`.
* 12-column grid on desktop, asymmetric compositions (5/7, 4/8) favoured over centred blocks.

### Motion

* Easing `cubic-bezier(0.22, 1, 0.36, 1)` ("expo-out"); durations 300ms (hover), 800ms (reveal), 1200ms (hero).
* `Reveal` component wraps Framer Motion `whileInView`, `once: true`, disabled under `prefers-reduced-motion`.

## 3. Components

`Container`, `Section`, `Eyebrow`, `DisplayHeading`, `Rule` (hairline, optional ornament), `ArchFrame` (arched image frame), `Picture` (AVIF/WebP/JPEG `srcset`), `Button` (`solid` champagne, `outline`, `link` with animated underline), `Reveal`, `ConfidentialitySeal`, `RomanNumeral`, `Field`/`TextInput`/`Select`/`Textarea`/`Checkbox` (accessible labels, error text linked via `aria-describedby`), `StepIndicator`, `AudioPlayer`, `EnvironmentRibbon`, `SkipLink`.

## 4. Imagery

Source: practitioner's Google Drive folder (88 photographs, 4 videos). Curated assets live in `frontend/public/media/` as `{slug}-{width}.{avif|webp|jpg}`; see `frontend/public/media/manifest.json` for alt text and intended placements. The studio backdrop is pale lavender, so portraits are placed inside arched frames on dark surfaces, and landscape session photographs receive a midnight gradient overlay. The four MP4s can be uploaded via the admin media library if a cinematic hero is wanted; the default hero uses a still for LCP performance.
