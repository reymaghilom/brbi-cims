# ADR 0012: Phase 15 official report generation

## Status

Accepted and implemented.

## Decision

Phase 15 uses one saved-data snapshot builder for browser preview, PDF, and DOCX. Dompdf 3.1 renders protected PDFs and PHPWord 1.4 creates editable OOXML documents. Both explicitly use 8.5 x 13 inch portrait pages with 0.45 inch default margins; no renderer default paper size is accepted.

The DOCX design follows the `compact_reference_guide` preset with a named `brbi_official_form` override: Arial 10 pt body text, 14 pt centered title, monochrome official-form styling, 10,944 DXA usable table width, fixed table geometry, compact cell padding, thin black borders, muted gray headers, and a quiet page-number footer. This override preserves the source forms' dense BRBI proportions on the approved longer paper.

Every generation request creates a `processing` database row before rendering. Completion records a private storage reference, MIME type, size, SHA-256 checksum, actor, source revision, template version, and generated time. A failure leaves the encoded source untouched, records a safe failure summary, removes any partial artifact, and remains retryable. Regeneration always creates the next immutable version.

Folder-wide and income-source-specific scope keys are distinct. The selected source must be active, belong to the route folder, and match the dedicated or fallback template family. Downloads use an authenticated policy-scoped controller; generated files are never placed on the public disk.

The active `required_reports` checklist item is satisfied only when a successful artifact exists for the CI/BI report, Residence & Business report, and every applicable active income source. Either PDF or DOCX satisfies a logical report scope. Failed or processing rows never count.

## Consequences

- Preview and files use the same normalized view data, minimizing cross-format drift.
- New official business templates can reuse the income-source registry and shared generators without a universal business table.
- Local storage references are compatible with a later Google Drive upload adapter.
- Image content is embedded only from safe existing private paths; unavailable references render a labeled placeholder.
- Report generation remains synchronous in Phase 15. Queueing and Drive backup remain later-phase work.
