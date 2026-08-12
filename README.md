# BRBI Credit Investigation Management System

Internal digital filing, encoding, tracking, reporting and document-management system for the Credit Investigation Team of Binhi Rural Bank Inc.

The project is being delivered in approval-gated phases. Phases 1 through 15 contain the Laravel foundation, approved database/data-model layer, private authentication, role authorization, reusable BRBI interface, Dashboard, client-folder lifecycle and contents, Client Information, CI Activities, CI / BI Report, template-selected Business / Income Sources, Residence & Business photo-report encoding, and protected official PDF/DOCX generation. See [the system architecture](docs/system-architecture.md), [UI foundation](docs/ui-foundation.md), [authorization matrix](docs/authorization-matrix.md) and [architecture decisions](docs/adr/).

## Requirements

- PHP 8.3 or newer with cURL, DOM, Fileinfo, GD, Mbstring, OpenSSL, PDO MySQL, PDO SQLite, XML/XMLWriter and ZIP.
- Composer 2.
- Node.js 24 and npm.
- MySQL for application data once the Phase 2 schema is approved.

## Local setup

```text
composer install
copy .env.example .env
php artisan key:generate
npm install
npm run build
php artisan test
```

Configure the MySQL connection in `.env`. Do not commit `.env`, API credentials, bot tokens, private keys or generated reports.

Provision the initial Administrator interactively after migrating:

```text
php artisan cims:create-admin
```

The command asks for the Administrator's details and password, refuses non-interactive execution and will not provision a second Administrator. Seeders never create an Administrator credential.

## Quality checks

```text
composer quality
npm run build
php artisan about --only=environment
```

Application timestamps use UTC. Display dates use Asia/Manila. Official BRBI report templates default to 8.5 x 13 inch paper.

## Current phase boundary

Phase 15 Official PDF/DOCX Report Generation is implemented. Do not begin Phase 16 Photos & Videos metadata until separately approved.
