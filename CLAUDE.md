# CLAUDE.md — BRBI Credit Investigation Management System

Permanent working rules for Claude Code sessions in this repository. This is an **existing, working** Laravel 13 / PHP 8.3 application (BRBI Credit Investigation Management System — Blade + vanilla JS + Tailwind v4, no Vue/Alpine/Livewire/jQuery). It is not a greenfield project: assume every existing behavior is intentional until proven otherwise.

## Core working principles

- **Preserve all existing features** unless the user explicitly asks to change them.
- **Make only the requested change.** Do not refactor, "clean up," or improve unrelated code, files, or patterns while doing a task.
- **Keep diffs small and focused** — touch only what the requested change requires.
- **Before modifying a feature**, inspect the actual active Controller → FormRequest → Action → Service → View → JS path used by that feature. Don't assume based on naming alone; verify.
- **Do not assume unused-looking variables, props, or views are dead code.** Verify actual usage (grep call sites, check Blade includes/props) before removing anything — even if it looks unused (e.g. this codebase is known to have at least one such case: `$businesses` in `business-edit.blade.php`).
- **After every code change**, summarize exactly which files were modified and why.

## Domain rules (do not violate)

- One `ClientFolder` can have **multiple independent `IncomeSource` records** (plain `hasMany` via `income_sources.client_folder_id` — no pivot, no JSON array). Each `IncomeSource` owns exactly one `BusinessReport` or one `GeneralIncomeSourceReport` (mutually exclusive, 1:1).
- **Never let a save/edit action for one business/income source overwrite another.** Row identity must always be scoped by the specific `IncomeSource`/child-row `id`, never inferred by position or template type alone.
- The **CI/BI Report remains one report per client folder** (`CibiReport` is `hasOne` on `ClientFolder`, unique FK). Do not turn it into a multi-record structure.
- Preserve the **existing modal/iframe/postMessage workflow** (CI/BI Report and Business Report both open in a `<dialog>` containing an `<iframe>`, save completion communicated back to the parent page via `window.postMessage({type: 'brbi:cibi-saved', ...})`) unless the user explicitly asks to change it. The `data-cibi-report-*` / `data-business-report-*` hooks in Blade and `app.js` must stay in sync — renaming one side silently breaks the modal.
- Preserve **nested route `scopeBindings()`** on routes like `{clientFolder}/{incomeSource}`, `{clientFolder}/{ciActivity}`, `{clientFolder}/{generatedReport}`, `{clientFolder}/{mediaReference}`. This is what prevents cross-folder access via id-guessing — never remove it to "simplify" a route.
- Preserve the **repeater `id` / `_delete` conventions and existing `sync()`/`syncTenants()` behavior** used for dynamic rows (bank accounts, loans, branches, products, suppliers, observations, competitors, tenants, declared income items, etc.). Rows carry an optional `id` (existing) and a boolean `_delete` flag; the sync logic in each Action creates/updates/soft-deletes accordingly and must stay aligned with the repeater JS's field naming.
- Preserve **existing Save/Update logic** (Actions, FormRequest validation rules, completion/state transitions) unless the user specifically asks to change that logic.

## Database & migrations

- **Do not edit already-applied migrations.** Create a new migration only when a schema change is genuinely required.
- **Avoid database/schema changes for presentation-only changes.** If a request is about layout, labels, or styling, it should not touch migrations or table structure.
- Never run migrations, seeders, or destructive DB commands without explicit user instruction.

## `config/business-report-templates.php`

Treat this file carefully. It defines per-business-template field/column/table schemas using **shared helper closures** (`$field`, `$column`, `$table`, `$summary`, and shared column arrays like `$branchColumns`, `$supplierColumns`, etc.) that multiple template types depend on. Editing a shared closure or shared array in place can silently change unrelated business templates — when a change is meant for one template, add/override for that template specifically rather than mutating shared structures.

## UI / styling

- UI must remain **mobile responsive, modern, professional banking/internal-system style: compact, clean, and visually uniform.**
- **Reuse existing Blade components, CSS classes, UI patterns, and validation styles** instead of inventing duplicates. Check `resources/views/components/ui/` and `components/form/` first.
- When adding or modifying labels, inputs, tables, radio buttons, checkboxes, buttons, spacing, borders, fonts, or other UI elements, **reuse the nearest equivalent approved styling already present in the project.**
- **Do not invent new font sizes, font weights, colors, borders, spacing, or component styles** when an equivalent already exists in the Tailwind theme tokens or existing components (e.g. `rounded-card`, `rounded-control`, `text-text-muted`, `bg-brand-primary`, `ui-icon-button`, etc.).

## Data integrity

- **Do not hard-code sample/reference data into forms or reports.**
- Reference files/images (e.g. layout mockups, sample screenshots) control **layout/design only** — actual displayed values must always come from the database or genuine user input, never fabricated or copied from a reference.
