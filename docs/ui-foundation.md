# BRBI CIMS UI foundation

Status: Implemented through Phase 5

## Design system

The interface uses centralized semantic tokens in `resources/css/app.css`. Blade templates refer to token names rather than hex colors. The primary palette includes BRBI sidebar/primary greens, folder yellow, progress amber, success green, destructive red, app/surface neutrals and semantic text/border colors. Card/control/panel radii, card/floating shadows, page spacing and professional system typography are also tokens.

The encoding interface and official report output remain independent. `resources/css/print/official-report.css` establishes only the shared 8.5 x 13 inch page, margin, table-border and page-break behavior. It is not a complete report template.

## Responsive shell

- Desktop (`lg` and above): persistent 18rem sidebar, sticky topbar and comfortable constrained workspace.
- Tablet/mobile: off-canvas sidebar with backdrop, Escape dismissal, close button and focus movement; the topbar remains available.
- Mobile: minimum 44px controls, single-column cards/forms, responsive tables replaced by mobile cards where needed, and body-level horizontal overflow prevention.

The sidebar contains all approved destinations. Users, Settings and Audit Trail are emitted only when their existing policies allow them. Later module destinations render explicit foundation placeholders and contain no later-phase business logic.

## Component catalog

Anonymous Blade components are stored under `resources/views/components/ui` and `resources/views/components/form`:

- Layout/data: page header, breadcrumb, summary card, folder card, status badge, progress bar, client header and module card.
- States: empty, loading, retry/error, toast, integration badge and missing-items summary.
- Forms: form section, input, select, textarea, checkbox/radio choice group, validation message and sticky toolbar.
- Interaction: modal, confirmation dialog, context menu, tabs/tab panel and accordion.
- Workflow presentation: activity checklist item, note timeline, media card, report preview toolbar and recycle-bin item.

Native `dialog` and `details` elements provide baseline semantics. The small shared JavaScript module handles the drawer, focus return, modal closing, toast dismissal and ARIA tab keyboard controls. Reduced-motion preferences disable non-essential animation.

## Preview

Administrators can open `/admin/ui-foundation` to inspect all component patterns using sample presentation data. This route is policy/role protected and is not a business dashboard or module implementation.
