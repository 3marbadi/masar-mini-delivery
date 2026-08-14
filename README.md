# Masar Mini Delivery System

Supporting delivery management system used to demonstrate integration with the Masar decision-support and route optimization system.

## Overview

Mini Delivery is a small Laravel administration system for representatives, customers, delivery orders, assignments, local delivery results, and customer delivery history. It provides a durable outbound integration that supplies operational delivery data to Masar.

## Relationship with Masar

Mini Delivery is not the main Masar application. It is a supporting system used as an external source of orders and related data:

```text
Mini Delivery
  -> versioned integration events
  -> Masar
```

Mini Delivery owns external customer and representative data, order assignments, locations, and historical delivery outcomes. Masar owns customer-readiness processing, tour planning, route generation, and route decision support.

## Main Features

- Representative management.
- Customer management and customer delivery history.
- Delivery order management.
- Assignment and reassignment workflows.
- Order cancellation and local delivery-result recording.
- Filament administration dashboard.
- Durable integration outbox.
- Reliable, retry-aware integration sender.
- Immutable, versioned integration-event payloads.

## Technology Stack

- PHP 8.3 or later.
- Laravel 13.
- Filament 5 and Livewire.
- MySQL.
- Node.js and npm for the Vite frontend build.

## Requirements

- PHP 8.3+ with the extensions required by Laravel and MySQL.
- Composer.
- MySQL.
- Node.js and npm.

## Installation

```bash
git clone <repository-url>
cd masar-mini-delivery
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
```

On Windows Command Prompt, use `copy .env.example .env` instead of `cp`.

Configure the `DB_*` values in `.env`, create the configured database, then run:

```bash
php artisan migrate
php artisan make:filament-user
```

The Filament command prompts for local administrator details. Do not store production or personal credentials in source files.

## Running the Application

```bash
php artisan serve
```

The administration panel is available at `/admin` on the configured application URL.

For frontend development, run this in a separate terminal:

```bash
npm run dev
```

## Integration Configuration

Set these environment variables locally:

```dotenv
MASAR_INTEGRATION_URL=
MASAR_INTEGRATION_TOKEN=
```

`MASAR_INTEGRATION_URL` is the receiving Masar service base URL. `MASAR_INTEGRATION_TOKEN` is the shared integration credential and must be managed as a secret.

## Sending Integration Events

```bash
php artisan integration:send
```

The command selects eligible pending outbox events and sends them to the configured Masar receiving API. Retryable failures remain pending; terminal protocol or validation failures are recorded without exposing the integration token.

Production deployments should invoke the sender through an appropriately supervised scheduler or worker process.

## Supported Integration Events

- `order.assigned`
- `order.updated`
- `order.reassigned`
- `order.cancelled`

Current-order completion, including `delivered` and `not_delivered`, remains local to Mini Delivery and is not synchronized to Masar in integration contract V1.

## Integration Contract

The authoritative payload, identity, versioning, idempotency, authentication, and error-handling rules are documented in [docs/masar-integration-contract-v1.md](docs/masar-integration-contract-v1.md).

## Running Tests

```bash
php artisan test
```

Code formatting and package metadata can be checked with:

```bash
vendor/bin/pint --test
composer validate --no-check-publish
```

Current verification status: the complete application suite passes with 77 tests and 488 assertions. This count is informational and may grow as the project evolves.

## Security Notes

- Never commit `.env`, application keys, database passwords, or integration tokens.
- Never log authorization headers or bearer tokens.
- Use HTTPS for all deployed integration traffic.
- Use distinct development and production credentials.
- Rotate any credential immediately if it may have been exposed.
- Review staged content for secrets before every release.

## Project Scope

This project intentionally does not provide:

- Payment or inventory management.
- Live GPS tracking.
- A customer mobile application.
- A complete logistics platform.
- Bidirectional synchronization with Masar.
- Dynamic route-impact classification or route re-evaluation.
