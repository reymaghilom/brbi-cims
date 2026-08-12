# ADR 0007: Phase 5 global BRBI UI foundation

Status: Implemented for review

BRBI CIMS uses anonymous Blade components and Tailwind 4 semantic theme tokens for its shared interface. The shell is purpose-built around BRBI dark green, restrained banking surfaces and a yellow physical-folder metaphor rather than a generic dashboard template.

The desktop shell keeps a persistent navigation sidebar. Smaller viewports use an off-canvas drawer and single-column content. Authorization checks remain responsible for emitting Administrator navigation; the UI never replaces server-side policies. Existing login, required-password-change and user-management behavior remains unchanged beneath the new presentation.

Small framework-free JavaScript behaviors are preferred for the global foundation. Native dialogs, disclosures, semantic labels, ARIA tab relationships, keyboard navigation, Escape handling, focus restoration, live status regions and visible focus treatments establish the accessibility baseline without adding a frontend-framework dependency.

Official outputs do not inherit the application shell or encoding styles. A separate print stylesheet defines the approved 8.5 x 13 inch page capability, predictable margins, table borders and page-break utilities; report-specific templates remain in their later phases.

Phase 5 adds only static placeholder destinations and an Administrator-only component preview. It does not implement dashboard queries, client-folder workflows, reports, activity processing, media handling, Google Drive or Telegram logic.
