BRBI Credit Investigation Management System
A web application for managing the credit investigation workflow of a rural bank's Credit Investigation team: client folders, field activities, investigation reports and official PDF/DOCX/Excel outputs in one place.
Project Overview
The BRBI Credit Investigation Management System (BRBI CIMS) is an internal web application developed as part of my professional work at Binhi Rural Bank Inc. It supports the bank's Credit Investigation (CI) team in collecting, organizing and reporting the information gathered while evaluating loan applicants and their co-makers.

Credit investigation involves many separate pieces of work for each applicant: bank and cooperative inquiries, asset checks, residence and business visits, income-source validation, and a formal CI/BI report. Without a central system this work is spread across paper forms, spreadsheets and photo folders, which makes it hard to track what is still outstanding and to produce consistent official reports.

BRBI CIMS addresses this by:
- Giving every applicant a client folder that holds all investigation records for the applicant and any co-makers.
- Tracking each CI activity through scheduling, follow-up and completion, with supporting proof.
- Providing structured encoding forms that mirror the bank's official report layouts.
- Generating print-ready PDF, DOCX and Excel reports directly from the encoded data.
- Showing investigators and administrators the progress of each folder and the work that still needs attention.
Key Features
Client Folders
- Each applicant gets a client folder with a generated folder number, an assigned investigator and a progress status (On Progress / Completed).
- Folders support multiple co-makers. Each person (applicant or co-maker) has their own client information, activities, reports and checks, and the interface switches between them.
- Live search, name suggestions, folder renaming, soft deletion and folder history.
- Progress is calculated from a single definition of mandatory investigation requirements, so the folder status, dashboard and reports agree on what "complete" means.
Client Information
- Validated personal details, addresses and related information for the applicant and each co-maker, reused across the reports that need them.
CI Activities
- A per-folder activity tracker with a default checklist of investigation activities plus custom activity types that the team can add, edit and deactivate.
- Activity statuses: Pending, Scheduled, For Follow-up and Completed, with optional date-only or date-and-time schedules.
- Bank / Cooperative checks and Asset checks are tracked as individual targets, each with its own schedule, status and completion details.
- Supporting proof photos, activity notes and an activity history feed.
- Individual and batch submission, bulk completion and a global CI Activities page across all folders.
CI / BI Report
- One CI/BI report per client folder (per person), encoded in a form that follows the bank's official one-page layout: personal, neighborhood, residence and financial investigation results.
- Repeatable sections for bank accounts, loan records, credit checks, legal findings and income sources.
- Signatory (CI-in-charge) management with a reassignment history.
Business and Income Source Reports
- A client can have multiple independent income sources, each with its own report.
- About 20 business report templates for different business types (for example retail, pharmacy, trucking, leasing, contractor, restaurant, farming and remittance), each with its own fields, tables and validation questions defined in configuration.
- A Sources of Income Declared by Client report for non-business income.
- Custom business categories for businesses not covered by a template.
- Duplicate-template guards and contributor (participating investigator) tracking per report.
Residence and Business Checks
- Photo-based residence and business check reports with categorized photos and business photo groups.
- Google Maps link, map screenshot and location capture for site visits.
- Preview, batch print and batch export of the photo reports.
Reports and Document Generation
- Official reports rendered from Blade templates sized for the bank's 8.5 × 13 inch paper.
- PDF export (dompdf), DOCX export (PHPWord) and Excel export (PhpSpreadsheet, using official workbook templates).
- Single and batch exports for business reports and residence/business check reports.
- A global Reports workspace for finding and exporting reports across folders.
Dashboard
- KPI summary, completion trend chart, workload overview and a "work today" queue.
- Recent activity based on recorded audit events.
Administration
- User management (create, edit, activate/deactivate, administrator-initiated password reset, profile photos).
- A system-wide evidence storage setting that chooses where new field photos are stored (local private storage or Cloudinary).
- An operational data reset tool for clearing investigation data.
Technology Stack
Area	Technologies
Backend	PHP 8.3, Laravel 13
Frontend	Laravel Blade templates, vanilla JavaScript, Tailwind CSS v4
Database	MySQL (application data); SQLite in-memory for automated tests
Authentication & Authorization	Laravel session authentication, custom middleware, Laravel policies and gates, role-based access
Document Generation	dompdf (PDF), PHPOffice PHPWord (DOCX), PHPOffice PhpSpreadsheet (Excel)
File & Media Storage	Laravel filesystem (private local storage), Cloudinary PHP SDK (optional)
Scheduling & Notifications	Laravel Task Scheduling, Artisan commands, Laravel database notifications, mail notifications for password resets
Build Tools	Vite 7, laravel-vite-plugin, @tailwindcss/vite, Composer, npm
Testing & Quality	PHPUnit 12, Mockery, Faker, Laravel Pint


System Architecture
BRBI CIMS is a Laravel modular monolith. The client folder is the central record: every report, activity, note and media file belongs to a specific client folder, and most routes are nested under it.
Browser (Blade pages + vanilla JS, modal/iframe editors)
        │
        ▼
Routes ── middleware: auth, current-session check, no-cache, password-change, role
        │
        ▼
Controllers ── FormRequests (validation and authorization)
        │
        ▼
Actions (single-purpose write operations, transactions)
        │
        ▼
Services (completion evaluators, progress, dashboard data,
          report builders and exporters, media storage)
        │
        ▼
Eloquent models ── MySQL
        │
        └── Filesystem / Cloudinary (photos and generated files)
Key design points:
- Layered request handling. Controllers stay thin; validation lives in FormRequests, writes live in Action classes, and reusable logic lives in Services.
- Policy-based authorization on every client-folder resource, plus nested route bindings scoped to the parent folder so records cannot be reached through another folder's URL.
- Concurrency protection. Editable records carry revision numbers that are checked under row locks, so a stale form cannot silently overwrite a colleague's newer save. An editing-presence heartbeat warns users when someone else is editing the same record.
- Separate encoding and output layers. Encoding screens are responsive forms; official outputs are dedicated print templates, so the encoding UI can change without affecting report layouts.
- Configuration-driven business templates. Business report fields, tables and questions are defined in config/business-report-templates.php and rendered by shared Blade partials.
- Durable file cleanup. File deletions that fail after a database commit are queued in a pending-cleanup table and can be retried with an Artisan command.
Data Model (high level)
- users: system users and their roles.
- client_folders: one per applicant; owns client_information, addresses and co_makers.
- ci_activities: investigation activities, with ci_activity_bank_targets, ci_activity_asset_targets, notes and proof media.
- cibi_reports: one CI/BI report per folder/person, with child tables for bank accounts, loan records, credit checks, legal findings and income sources.
- income_sources: many per folder; each owns either a business_reports record (with branches, products, suppliers, observations, competitors, properties and tenants) or a general income-source report with declared income items.
- residence_checks / business_checks: site-visit reports with their photos and contributors.
- generated_reports, media_references, audit_logs, notifications, system_settings: generated files, media metadata, audit trail, in-app notifications and system configuration.
Integrity is enforced with foreign keys, unique constraints (for example one CI/BI report per folder/person), soft deletes on recoverable records and transactional writes.
Application Workflow
1. Create a client folder for a loan applicant and assign a credit investigator.
2. Encode client information for the applicant and add co-makers if needed.
3. Plan and track CI activities: schedule bank/cooperative inquiries, asset checks and other activities, record results and attach proof.
4. Validate income sources by selecting the matching business template (or the declared-income form) for each source of income.
5. Record residence and business checks with site photos and map evidence.
6. Complete the CI/BI report, which brings together the investigation findings.
7. Generate official reports as PDF, DOCX or Excel for submission.
8. Monitor progress on the folder page and dashboard until all mandatory requirements are complete.
User Roles and Permissions
Role	Purpose
Administrator	Manages users and system settings, and has access to all client folders.
Credit Investigator	Performs field investigation work: creates and works on client folders, activities, reports and checks.
Senior Credit Investigator	Has all Credit Investigator capabilities plus the ability to reassign a CI/BI report's signatory.


Access is enforced on the server through middleware and Laravel policies; hiding navigation items is never the only control. Additional account protections include forced password change, invalidation of other sessions after credential changes, rate-limited login and password-reset endpoints, and no-cache headers on authenticated pages.
Activity Scheduling and Notifications
- CI activities and individual bank/asset targets can be scheduled with a date, or a date and time.
- When an activity is scheduled or rescheduled, the system records an in-app notification for the user.
- A Laravel scheduled command, ci-activities:send-reminders, runs every minute and creates a due reminder for each scheduled activity or target whose time has arrived. Each reminder is sent once, to the activity's creator, using row locks to prevent duplicates.
- Notifications are stored with Laravel's database notification channel and shown in the header notification panel, which refreshes every 30 seconds. Users can open a notification to go directly to the related folder's activities and mark it as read.
Reminders require the Laravel scheduler to be running (for example php artisan schedule:work locally, or a cron entry calling php artisan schedule:run on a server).
Reports and Document Management
Report	Formats
CI / BI Report	PDF, Excel
Business / Income Source Report (single and batch)	PDF, Excel, print preview
Sources of Income Declared by Client	PDF
Residence & Business Photo Report (single and batch)	PDF, DOCX, print preview


- Generated reports are recorded per folder and can be regenerated and downloaded through authorized routes.
- Field photos and proof images are stored privately and served only through authorized controller routes. New uploads can go to local storage or Cloudinary depending on the administrator setting; existing files always remain with the provider they were saved to.
- Times are stored in UTC and displayed in Asia/Manila time.
Installation and Local Development
This is an internal application developed for Binhi Rural Bank Inc. The steps below describe the development setup for reference only. They are not an invitation to deploy or reuse the software.

Requirements
- PHP 8.3+ with the extensions required by Laravel and the document libraries (including DOM, Fileinfo, GD, Mbstring, OpenSSL, PDO MySQL, PDO SQLite, XML and ZIP)
- Composer 2
- Node.js and npm
- MySQL
Setup
composer setup              # composer install, create .env, generate key, npm install, npm run build
# configure the database connection in .env
php artisan migrate
php artisan cims:create-admin
cims:create-admin interactively creates the initial Administrator. It refuses to run non-interactively and will not create a second Administrator; seeders never create administrator credentials.

Run the development environment
composer dev                # web server, queue listener, scheduler and Vite together
Never commit .env, API credentials or generated reports.
Testing and Code Quality
The project includes an automated PHPUnit suite under tests/ covering authentication, authorization, administration, client folders, CI activities, reports, the dashboard, UI rendering and database safety guards.
composer test               # clears config cache and runs the test suite
composer quality            # Laravel Pint style check + test suite
vendor/bin/phpunit          # run PHPUnit directly
Tests use an in-memory SQLite database, and the suite includes guards that block destructive database commands and factory writes outside the testing environment.
Technical Highlights
- Complex domain modeling: one client folder holds multiple people, multiple independent income sources, about 20 business report templates and nested repeatable sections, with records always identified by their own IDs rather than by position.
- Authorization design: role-based policies combined with folder-scoped nested route bindings.
- Data-loss prevention: optimistic concurrency with revision checks under row locks, editing-presence warnings, no-change detection and conflict-specific exceptions.
- Document generation: the same encoded data rendered to fixed-layout PDF, editable DOCX and template-based Excel workbooks.
- Scheduled background processing: idempotent, lock-protected reminder processing driven by Laravel's scheduler.
- File handling: private media storage, an optional cloud provider chosen per upload, and durable retry of post-commit file cleanup.
- Maintainability: a layered Controller → FormRequest → Action → Service structure, enums for domain states, reusable Blade UI components and an extensive feature test suite.
Developer and Professional Contribution
	
Developer	Rey C. Maghilom
Role	Sole Full-Stack Developer
Organization	Binhi Rural Bank Inc.
Project Type	Professional Workplace Project


I developed BRBI CIMS as its sole developer during my employment at Binhi Rural Bank Inc. My responsibilities covered the full development lifecycle:
- Translating the CI team's workflow and official paper forms into system requirements and data models.
- Designing the application architecture and MySQL database schema.
- Building the backend with Laravel: validation, business logic, authorization, scheduling and notifications.
- Building the responsive Blade/Tailwind interface and vanilla JavaScript interactions.
- Implementing official PDF, DOCX and Excel report generation.
- Writing automated tests and maintaining code quality tooling.
Project Status
Completed. The application's planned modules are implemented. Further work, if any, will be maintenance or new requests from the organization.
Usage and Intellectual Property
This application was developed for Binhi Rural Bank Inc. as part of my employment. This repository is shared to show my work as a developer. It does not grant any license to use, copy, modify or distribute the software, and it does not represent an endorsement by Binhi Rural Bank Inc. Names, logos and report formats belonging to Binhi Rural Bank Inc. remain the property of the bank.
Additional Documentation
- [System architecture](docs/system-architecture.md)
- [UI foundation](docs/ui-foundation.md)
- [Authorization matrix](docs/authorization-matrix.md)
- [Architecture decision records](docs/adr/)
These documents were written during development and may describe earlier plans that differ from the final implementation.
