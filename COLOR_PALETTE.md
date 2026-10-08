# SPTS — Colour Palette

Extracted from the live code (`*.php`, `assets/css/*.css`, `assets/js/*.js`, favicon). "Uses" is how many times the colour appears in the code, which shows how central it is.

The palette is **Bootstrap 5 + Tailwind-style Slate greys**, anchored by two Nilai University brand colours: **Navy** and **Teal**.

---

## 1. Brand colours

| Swatch | Name | Hex | Uses | Where it's used |
|---|---|---|---|---|
| 🟦 | **Navy (primary brand)** | `#1A3A5C` | 133 | Top bar, headings, table headers, favicon background, Student ID text, chart headings |
| 🟩 | **Teal (secondary brand)** | `#0D7377` | 124 | Primary buttons, active sidebar item, avatar, top-bar bottom border, links, section labels |
| | Teal (hover) | `#0A5E62` | 29 | Hover state of teal buttons and links |
| | Teal (light) | `#7DD3D6` | 9 | Weight labels in table headers, e.g. "(20%)" |
| | Teal tint | `#E0F4F4` · `#EAF7F8` · `#F4FBFC` | 9 | Peer-mentor badge, info boxes, the "file selected" box on CSV upload |
| 🟨 | **Amber (accent)** | `#F59E0B` | 23 | Active-semester name in the sidebar, keyboard focus outline, the "At Risk" status |

## 2. Academic status colours (core system colours)

Defined once in [includes/grading.php](includes/grading.php) (`status_hex_color()`) and reused by charts, badges and progress bars.

| Status | Score | Main colour | Bootstrap badge | Light tint (background) |
|---|---|---|---|---|
| **Excellent** | ≥ 80 | `#198754` green | `bg-success` | `#D1FAE5` / `#ECFDF5` |
| **Pass** | ≥ 60 | `#2563EB` blue | `bg-primary` | `#EFF6FF` / `#EAF4FB` |
| **At Risk** | ≥ 50 | `#F59E0B` amber | `bg-warning` (dark text) | `#FEF3C7` / `#FFFBEB` |
| **Fail** | < 50 | `#DC2626` red | `bg-danger` | `#FEE2E2` / `#FEF2F2` |
| Pending / none | — | `#64748B` slate | `bg-secondary` | `#F1F5F9` |

## 3. Neutrals (Slate scale): text, surfaces, borders

| Hex | Name | Uses | Role |
|---|---|---|---|
| `#FFFFFF` / `#FFF` | White | 82 | Cards, modals, text on dark backgrounds |
| `#F8FAFC` | Slate 50 | 40 | Input backgrounds, modal headers and footers |
| `#F0F4F8` | Page background | 17 | Body background on every page |
| `#F1F5F9` | Slate 100 | 17 | Year badges, secondary buttons, chart grid lines |
| `#E2E8F0` | Slate 200 | 40 | Borders, dividers, card outlines |
| `#CBD5E1` | Slate 300 | 20 | Top-bar user text, faded numbers |
| `#94A3B8` | Slate 400 | 27 | Sidebar link text, placeholders, "%" symbols |
| `#64748B` | Slate 500 | 43 | Muted/secondary text, subtitles, chart labels |
| `#475569` | Slate 600 | 21 | Body text in tables and lists |
| `#334155` | Slate 700 | 18 | Table cell text |
| `#1E293B` | Slate 800 | 24 | **Sidebar background**, chart tooltips |
| `#1A1A2E` | Ink | 17 | Main body text colour |
| `#6C757D` | Bootstrap grey | 12 | Subtitles and icons on the auth pages |

## 4. Alert / message colours

| Type | Background | Border | Text |
|---|---|---|---|
| ✅ Success | `#F0FDF4` | `#BBF7D0` | `#166534` |
| ❌ Error | `#FEF2F2` | `#FECACA` | `#991B1B` |
| ⚠️ Warning | `#FFFBEB` | `#FDE68A` / `#FCD34D` | `#92400E` |
| ℹ️ Info | `#EAF4FB` | `#BAE6FD` | `#0369A1` |

**Mentorship session badges:**
- **Pending:** `#FEF3C7` background, `#92400E` text
- **Approved:** `#D1FAE5` background, `#065F46` text
- **Completed:** `#E0F2FE` background, `#075985` text
- **Declined:** `#FEE2E2` background, `#991B1B` text
- **Cancelled:** `#F1F5F9` background, `#475569` text

## 5. Other accent colours

| Hex | Uses | Where |
|---|---|---|
| `#059669` | 9 | Emerald: success headings (CSV "Committed Records"), mentor "assign" buttons |
| `#EF4444` | 5 | Sidebar **Logout** link |
| `#D4A017` | 5 | Gold: "Best Score" trophy tile on the student dashboard |
| `#D97706` | 4 | Darker amber for warning icons and text |
| `#3B82F6` · `#4F8EF7` | 6 | Lighter blues in landing-page and stat-card icons |
| `#10B981` | 4 | Emerald icons on the landing page |

## 6. Dark mode palette ([assets/css/dark-mode.css](assets/css/dark-mode.css))

Turned on with the moon button and remembered per browser.

| Role | Light | Dark |
|---|---|---|
| Page background | `#F0F4F8` | `#0F172A` |
| Cards / panels | `#FFFFFF` | `#1E293B` |
| Secondary panels | `#F8FAFC` / `#F1F5F9` | `#16202E` / `#17212F` |
| Borders | `#E2E8F0` | `#334155` |
| Headings | `#1A3A5C` | `#E2E8F0` |
| Muted text | `#64748B` | `#94A3B8` |
| Chart grid | `#F1F5F9` | `#263447` |
| Deepest surfaces | — | `#0B1220` |
| Floating theme button hover | `#1A3A5C` | `#14304D` |
| Success alert (new pages) | `#F0FDF4` / `#166534` | `#0F2A1D` / `#BBF7D0` |
| Error alert (new pages) | `#FEF2F2` / `#991B1B` | `#2A1215` / `#FECACA` |

## 7. Charts (admin dashboard)

- **Doughnut (status distribution):** Excellent `#198754` · Pass `#2563EB` · At Risk `#F59E0B` · Fail `#DC2626`.
- **Bar chart (subject averages):** each bar takes the status colour of its average.
- **Tooltip background:** `#1E293B`. Labels and grid follow the light/dark tokens above.

---

## Quick reference: the core 8

```
Navy        #1A3A5C   brand, headers
Teal        #0D7377   buttons, active states
Slate 800   #1E293B   sidebar
Page bg     #F0F4F8   background
Excellent   #198754   green
Pass        #2563EB   blue
At Risk     #F59E0B   amber (also the accent)
Fail        #DC2626   red
```

### Inconsistencies noticed
- **Two success greens:** `#198754` (Excellent status) and `#059669` (success headings and buttons).
- **Two reds:** `#DC2626` (Fail) and `#EF4444` (Logout link).
- **Stray blues:** `#3B82F6` and `#4F8EF7` next to the status blue `#2563EB`.

These are minor, but picking one of each would tighten the design. Colours are written directly into each page's inline styles; apart from the dark-mode overrides there are no shared CSS variables, so changing a colour means finding every occurrence.
