# ADR 0004: Phase 2 schema implementation

Status: Implemented for review

The approved schema is implemented in eight dependency-ordered migrations. MySQL is the production database, while SQLite remains available for fast isolated tests. Foreign-key behavior, soft deletion, unique report ownership, template versioning and normalized repeating rows follow the approved architecture.

Generated report version uniqueness uses a required `scope_key` such as `folder:123` or `income:456`. This provides one portable unique constraint for folder-level and income-source-level versions because MySQL nullable columns do not provide reliable partial-unique behavior.

Each income source can have one dedicated business detail or one official general fallback detail. Each detail table has a unique income-source foreign key. Cross-table exclusivity remains an application transaction invariant for the template registry, as approved; seed consistency tests verify that every seeded income source owns exactly one compatible detail.

Reference data is safe to seed in every environment. A demonstration Credit Investigator and folders are created only in local/testing environments when `CIMS_SEED_DEMO_DATA=true`; its generated password is random and is not a usable known credential. Administrator accounts are never seeded.
