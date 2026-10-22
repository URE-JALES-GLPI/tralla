# Tralla Design System

## 1. Atmosphere & Identity

Tralla is an operational Kanban surface inside GLPI. It should feel immediate and familiar: a blue board, calm gray lists, white cards, and controls that stay out of the way until they are needed. The signature is the Trello-derived card/list rhythm: 272px lists, 8px cards, compact metadata, and an inline card composer that keeps creation in the list instead of interrupting the board.

## 2. Color

The existing `tralla.css` tokens are the source of truth. New UI must use these tokens or extend this table first.

| Role | Token | Light | Dark | Usage |
|---|---|---|---|---|
| Page/board fallback | `--tr-bg` | `#f4f5f7` | `#101418` | Page background |
| Surface/primary | `--tr-surface` | `#ffffff` | `#1b2129` | Cards, composer, modals |
| Surface/secondary | `--tr-surface-2` | `#f1f2f4` | `#232b35` | List background, neutral controls |
| Surface/tertiary | `--tr-surface-3` | `#e9ebef` | `#2d3742` | Pressed/selected wash |
| Text/primary | `--tr-text` | `#172b4d` | `#e8edf3` | Titles, card names |
| Text/secondary | `--tr-text-soft` | `#344563` | `#c6d0da` | Labels, secondary copy |
| Text/muted | `--tr-muted` | `#6b778c` | `#93a1b0` | Metadata, placeholders |
| Border/default | `--tr-border` | `#dfe1e6` | `#34404c` | Dividers, input rest |
| Border/strong | `--tr-border-strong` | `#c1c7d0` | `#465463` | Input hover |
| Accent/primary | `--tr-primary` | `#0079bf` | `#4aa3df` | Primary action, focus |
| Accent/primary-strong | `--tr-primary-strong` | `#026aa7` | `#6bb8ea` | Primary hover |
| Accent/soft | `--tr-primary-soft` | `#e4f0f6` | `#173247` | Informational wash |
| List background | `--tr-list-bg` | `#f1f2f4` | `#22272b` | Kanban list |
| Card background | `--tr-card-bg` | `#ffffff` | `#282e33` | Kanban card, composer |
| Card hover | `--tr-card-hover` | `#f7f8f9` | `#2c333a` | Card hover |
| Status/success | `--tr-success` | `#2e7d32` | `#81c995` | Done/approved |
| Status/warning | `--tr-warning` | `#b26a00` | `#f6c26b` | Pending |
| Status/danger | `--tr-danger` | `#c62828` | `#f28b82` | Rejected/destructive |
| Status/purple | `--tr-purple` | `#7b1fa2` | `#d7aefb` | Approval |

Rules:
- The primary accent is reserved for the one likely action: "Adicionar card", "Criar card", and focus.
- Status colors are semantic, never decorative. Source, due date, and counts stay muted.
- No new raw hex values in new component CSS. Add a token here and in `tralla.css` first.

## 3. Typography

The product uses the GLPI/system stack; do not add a webfont dependency.

| Level | Size | Weight | Line height | Usage |
|---|---|---|---|---|
| Board title | 18px | 700 | 1.3 | Board header |
| Section title | 20px | 600 | 1.3 | Modal title |
| List title | 14px | 600 | 20px | List header |
| Card title | 14px | 500 | 1.3 | Card name |
| Body | 14px | 400 | 1.5 | Controls, inputs |
| Body/sm | 12px | 400 | 1.5 | Card description, hints |
| Caption | 11px | 500 | 1.4 | Badges, metadata |
| Code | 11px | 600 | 1.4 | Card code, mono |

Font stack: `--tr-font` (`-apple-system`, `BlinkMacSystemFont`, `"Segoe UI"`, `Roboto`, `"Helvetica Neue"`, `Arial`, sans-serif`). Monospace: `"SFMono-Regular", Consolas, "Liberation Mono", monospace`.

Rules:
- Body text is not smaller than 12px; primary labels are 14px.
- Card and list titles wrap on words and use `overflow-wrap: anywhere` for unbroken strings.
- Metadata is never uppercase unless it is a status badge.

## 4. Spacing & Layout

Base unit: 4px.

| Token | Value | Usage |
|---|---|---|
| `--tr-space-1` | 4px | Icon-to-label, tight metadata |
| `--tr-space-2` | 8px | Card padding, list gutters, inline groups |
| `--tr-space-3` | 12px | Composer padding, list header inset |
| `--tr-space-4` | 16px | Board padding, modal body |
| `--tr-space-5` | 20px | Modal header inset |
| `--tr-space-6` | 24px | Modal body inset |

Board geometry:
- List width: 272px, flex-shrink 0.
- Board gap: 12px.
- Board horizontal padding: 16px.
- List radius: 12px.
- Card radius: 8px.
- Composer radius: 8px.
- At viewports below 680px, the board remains horizontally scrollable, but each list uses `width: 86vw` so the next list peeks in and no list is clipped.
- Scroll ownership: the board owns horizontal scroll; each `.tralla-cards` owns vertical scroll; the page itself does not scroll the Kanban region.

## 5. Components

### Board header
- **Structure**: back button, board title + type badge + optional comment, action cluster.
- **States**: default; hover on translucent actions; focus-visible ring; disabled/absent actions when rights are missing.
- **Spacing**: 8px vertical, 12px horizontal; 8px gap.
- **Accessibility**: header is a landmark; back link has an accessible label; board title is the page `h1`.

### List
- **Structure**: `section.tralla-list` > header + `.tralla-cards` + composer/button.
- **Variants**: normal, maintenance category (colored dot), drop target.
- **States**: default, hover (actions reveal), focus-within (actions stay visible), drag-over wash, empty. The "Adicionar card" button is disabled in the in-progress, approval, withdrawal, and done maintenance lists because those cards are created only in the pending list.
- **Motion**: box-shadow 140ms; no layout animation.
- **Scroll**: `.tralla-cards` is the vertical scroll owner; list is a flex column, empty lists keep a compact 72px minimum, and the composer stays outside the scroll area.

### Card
- **Structure**: optional maintenance status label, inline code + title, optional footer badges/avatar. Description stays in the card detail, never in the board preview.
- **Variants**: normal, maintenance status label, locked, dragging.
- **States**: default, hover shadow, focus-visible, dragging 3deg/opacity, locked cursor.
- **Spacing**: 8px 10px padding, 8px radius, 8px gap between cards.
- **Accessibility**: `article` with `role="button"`, `tabindex="0"`, Enter/Space opens; aria-label names the card.

### Inline card composer
- **Structure**: form with a textarea, primary submit button, icon cancel button.
- **Variants**: normal board only; maintenance uses the existing modal.
- **States**: empty (submit disabled), typing (submit enabled), submitting (busy), error (toast + draft retained), closed.
- **Spacing**: 8px panel padding, 8px gap to actions.
- **Accessibility**: visible/associated label or accessible name; autofocus on open; Enter submits, Shift+Enter adds a newline; Escape cancels; cancel returns focus to "Adicionar card".
- **Layout**: below the card scroll area, inside the list; stays visible while the list scrolls.

### Maintenance create modal
- **Structure**: name combobox + machine rows + actions, with quantity left and model right.
- **States**: default, validation error, busy, closed.
- **Rules**: source is not shown in creation; machine model + integer quantity are required; no description/label/serial fields. The entity list opens only after a click or typing, never on modal focus.
- **Accessibility**: dialog role, focus trap, Escape closes, errors announced by toast.

### Modal
- **Dismissal**: clicking the backdrop closes the modal only when the pointer press starts on the backdrop. Dragging a text selection from inside the dialog to the backdrop must not close it.
- **Implementation**: `showModal()` binds this rule automatically. Any custom modal must use the same `bindBackdropDismiss()` helper on its backdrop.

### Buttons
- **Variants**: primary, ghost, danger, icon.
- **States**: default, hover, active translateY(1px), focus-visible 3px accent ring, disabled opacity 55%, busy cursor-progress.
- **Motion**: 120ms background/box-shadow; no width/height animation.

## 6. Motion & Interaction

| Type | Duration | Easing | Usage |
|---|---|---|---|
| Micro | 120ms | ease-out | Hover, button press, card lift |
| Standard | 140-220ms | ease-in-out | List shadow, toast exit |
| Emphasis | 220ms | ease-out | Composer appearance |

Rules:
- Animate only `transform`, `opacity`, `box-shadow`, and `background`.
- The composer appears with a short opacity/translateY transition; no height animation.
- Hover reveal is for list actions and card edit affordance only. Nothing moves under the pointer while typing.
- `prefers-reduced-motion: reduce` collapses transitions to near-zero; focus and state changes remain.

## 7. Depth & Surface

Strategy: **mixed — tonal list, shadowed cards, translucent board chrome**.

| Level | Value | Usage |
|---|---|---|
| Card rest | `--tr-card-shadow` | Kanban cards |
| Card hover | `--tr-card-shadow-hover` | Hovered card |
| List | `0 1px 1px rgba(9, 30, 66, 0.25), 0 0 1px rgba(9, 30, 66, 0.32)` | Kanban list |
| Modal | `--tr-shadow-lg` | Dialogs |
| Composer | `--tr-card-shadow` | Inline creation panel |

Radii: `--tr-radius-sm` 6px for controls, 8px for cards/composer, 12px for lists/modals, 999px for pills/avatars. Nested corners stay concentric.

## 8. Accessibility Constraints & Accepted Debt

Constraints:
- Target WCAG 2.2 AA; body contrast at least 4.5:1.
- Every interactive element has a visible `focus-visible` state.
- Inline composer supports keyboard-only creation: Tab to textarea, type, Enter to submit, Escape to cancel.
- The board is horizontally scrollable by design; keyboard users can reach lists and cards through normal tab order.
- Icons that carry meaning have `aria-hidden` only when an adjacent text label exists; icon-only controls have `aria-label` and `title`.
- Dark theme uses the same semantic tokens, not inverted raw colors.

Accepted debt:
| Item | Location | Why accepted | Owner / Exit |
|---|---|---|---|
| Legacy CSS still contains some raw color values outside token declarations | `public/css/tralla.css` | Existing project; new code is tokenized and a full palette sweep is a separate refactor | Next visual-system consolidation |
| Horizontal board scroll on mobile | `public/css/tralla.css` | Kanban boards are intentionally two-dimensional; list width is clamped so nothing is clipped | Revisit only if a mobile single-list mode is requested |
