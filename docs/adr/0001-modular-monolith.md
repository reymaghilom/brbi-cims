# ADR 0001: Laravel modular monolith

Status: Accepted for Phase 1

BRBI CIMS is one Laravel 13 application running on PHP 8.3 or newer. The client folder is the aggregate and authorization boundary. Presentation, application, domain and infrastructure concerns are separated through Laravel conventions and service contracts, but remain one deployable application.

Microservices are not justified for the initial internal system. Report generation, Google Drive and Telegram remain replaceable infrastructure adapters and will run asynchronously in their approved phases.
