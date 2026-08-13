# Laravel Domain Driven

<a href="https://laravel.com/docs/13.x"><img src="https://img.shields.io/badge/Laravel-13-FF2D20.svg?style=flat&logo=laravel" alt="Laravel 13"/></a>
<a href="https://www.php.net/releases/8.4/en.php"><img src="https://img.shields.io/badge/PHP-8.4-777BB4.svg?style=flat&logo=php" alt="PHP 8.4"/></a>
<a href="https://github.com/othercodes/laravel-domain-driven/actions/workflows/test.yml"><img src="https://github.com/othercodes/laravel-domain-driven/actions/workflows/test.yml/badge.svg" alt="Test"/></a>

Welcome to Laravel Domain Driven! This is a Laravel starter designed to help you build applications using Hexagonal
Architecture and Domain-Driven Design (DDD).

With this starter, your project is organized into clear layers, keeping your core business logic separate from other
parts of your application. This makes your code easier to maintain and adapt over time. By using DDD, you can model your
business problems more clearly and build software that is easy to change as your needs evolve.

Whether you're starting a new project or improving an existing one, Laravel Domain Driven gives you a strong foundation
for building flexible, high-quality applications.

## 🤔 Why use Laravel Domain Driven?

**Laravel Domain Driven** brings together the power of **Laravel**, **Domain-Driven Design (DDD)**, and **Hexagonal
Architecture**, offering key benefits:

- **Clear and Organized Code**: DDD and Hexagonal Architecture help keep your business logic separate from other parts
  of the app, making your code easier to understand and manage.

- **Easy to Scale and Update**: This structure allows your application to grow and change over time without needing
  major rewrites.

- **Well-Structured Application**: By combining Laravel’s features with DDD, your project becomes more organized, making
  it easier to work with.

- **Easier to Test and Maintain**: Hexagonal Architecture focuses on clean design, making it simpler to test and
  maintain your code.

- **The Power of Laravel**: Laravel provides a modern and elegant framework with built-in tools for routing,
  authentication, database management, and more, helping you develop quickly and efficiently while maintaining
  high-quality code.

Using **Laravel Domain Driven** helps you build clean, flexible, and long-lasting applications.

## ✨ Features

The user-facing features started life as **Laravel Jetstream** scaffolding, but Jetstream itself is not a dependency:
what is here is **Laravel Fortify** and **Inertia**, rearranged into **Domain-Driven Design (DDD)** and **Hexagonal
Architecture**. Key features include:

- **Authentication**: User registration, login, password reset, email verification, and more, powered by **Laravel
  Fortify**.
- **Two-Factor Authentication (2FA)**: Extra layer of security for user accounts, integrated with **Laravel Fortify**.
- **Session Management**: Manage active sessions and log users out of other devices.
- **Profile Management**: Users can update their profile information, including email and password.
- **Inertia.js with Vue 3**: Provides a modern, single-page app experience with a clean structure for building
  interactive UIs, while still leveraging the full power of Laravel on the backend.
- **Tailwind CSS**: Utility-first CSS framework that makes it easy to create responsive and customized designs without
  writing custom CSS.
- **Laravel Sanctum**: Session authentication for the Inertia front end, and API tokens through the `APITokens`
  aggregate, which replaces Sanctum's own model so tokens can belong to a uuid user.
- **ComplexHeart**: Domain events and the declarative bounded context provider both come from
  [complex-heart/on-laravel](https://github.com/ComplexHeart/on-laravel).
- **Laravel Sail**: Lightweight Docker environment for developing Laravel applications locally, simplifying setup and
  development. It is the supported way to run this project, with PHP 8.4 and Node 24 pinned in `docker-compose.yml`.
- **Laravel Pint**: A zero-config PSR-12 compliant PHP code style fixer, ensuring consistent coding standards across
  your project.
- **Larastan**: Static analysis tool that helps detect potential issues in your code, improving code quality and
  reducing bugs.
- **Pest**: A modern testing framework for PHP that makes testing simpler, faster, and more readable, providing a fluent
  and expressive syntax for writing tests. Includes **architecture tests** that enforce DDD layer separation and bounded
  context isolation.

These features are structured in a way that keeps your business logic clean, maintainable, and aligned with modern
development practices.

## ⚙️ Installation

To get started with Laravel Domain Driven, you just need to execute the following command:

```bash
composer create-project usantisteban/laravel-domain-driven:dev-master my-app
```

## 🚀 Startup

Once installed, start the application with the following commands:

```bash
# Start Sail services (MySQL, Redis, Mailpit)
./vendor/bin/sail up -d

# Generate application key
./vendor/bin/sail artisan key:generate

# Run database migrations
./vendor/bin/sail artisan migrate

# Install frontend dependencies and build assets. Node 24 comes from the Sail
# image, pinned by NODE_VERSION in docker-compose.yml; .nvmrc matches it for
# anything you run on the host instead.
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Link the public/storage directory to the storage/app/public directory
./vendor/bin/sail artisan storage:link

# Run tests to verify everything works
./vendor/bin/sail test

# Run only architecture tests (DDD layer and context isolation)
./vendor/bin/sail pest --testsuite=Arch
```

For development with HMR, queue worker, and log tailing:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev          # Vite, with HMR on 5173
./vendor/bin/sail artisan queue:listen --tries=1
./vendor/bin/sail artisan pail --timeout=0
```

There is also a `composer dev` script that runs the four at once, but it calls `php` and `npm` directly, so it needs
them on your host. Sail is what this project supports.

Deploying a rebuilt front end needs one more step:

```bash
./vendor/bin/sail artisan view:clear
```

Inertia 3 reads the page payload from `<script data-page>`. A cached Blade view still serving the Inertia 2
`<div data-page="{json}">` markup leaves the application unhydrated, with no error anywhere to say why.

## 🛠️ Scaffolding

Creating a bounded context by hand means a directory, a service provider and an entry in
`bootstrap/providers.php`, all of which have to match the conventions the architecture tests enforce.
There is a command for that:

```bash
./vendor/bin/sail artisan ldd:make:bounded-context Billing
```

Routes live inside each context rather than in the central `routes/web.php`, so ask for the files you
need and the provider will declare them for you:

```bash
./vendor/bin/sail artisan ldd:make:bounded-context Billing --web --api
```

No layer directories are created. A context earns its `Domain`, `Application` and `Infrastructure`
one aggregate at a time:

```bash
./vendor/bin/sail artisan ldd:make:aggregate Billing Invoice
```

That writes the aggregate root, its repository contract and Eloquent implementation, and its
exceptions. It then binds the repository in the context's service provider, which is what makes it
resolvable at all. Everything else is opt in, because real aggregates rarely need every layer:

| Flag | Adds |
|---|---|
| `--migration` | A migration, and its path in the provider's `$migrations` |
| `--factory` | A model factory, routed through the aggregate's `new()` |
| `--events` | A `Created` domain event, recorded by `new()` and published by you |
| `--requests` | A form request |
| `--web` | An Inertia controller, rendering through `InertiaController` |
| `--api` | An API controller |
| `--all` | All of the above |

The table name defaults to the pluralised aggregate. Two contexts may each own an `Invoice`, but
only one can create the `invoices` table, so `--table=billing_invoices` renames it for the second.

An aggregate records its domain events; publishing them belongs to the application layer, which is
what the last two commands fill in:

```bash
./vendor/bin/sail artisan ldd:make:use-case Billing Invoice CreateInvoice --publishes
./vendor/bin/sail artisan ldd:make:event-handler Billing Invoice NotifyAccounting --queued
```

`--publishes` writes the idiom that closes the loop: save through the repository and publish the
recorded events inside the same transaction, so a failing listener cannot leave the aggregate
persisted. Without it the events pile up on the instance and vanish with it.

The handler is the one that needs wiring. It is declared in the provider's `$events`, and one that
is missing from there is simply never called. That is why the command refuses to add a second
handler for an event that already has one, rather than leave two entries under a key where PHP
keeps only the last.

All four commands only ever add. Run one again with a flag you skipped and it writes what is missing,
leaves every file already on disk alone, since your migration may have been applied and the provider
carries wiring no stub knows about, and prints the lines to add by hand.

## 📁 Structure

The structure of the `app/` directory in **Laravel Domain Driven** starter package is organized around different
contexts, each representing a specific area of functionality in the application. This setup follows Domain-Driven
Design (DDD) principles and Hexagonal Architecture, providing a clear separation of concerns.

**Bounded Contexts**: Each context represents a specific part of the business domain and is loaded using a dedicated
service provider. For example:

* 🛡️ **Identity And Access**: Contains everything related to authentication and authorization.
* 🔄 **Shared**: Holds common functionality used across the entire application, such as utilities, helpers, and common
  services.

```
app
├── IdentityAndAccess
│   ├── APITokens
│   ├── IdentityAndAccessServiceProvider.php
│   ├── Shared
│   └── Users
└── Shared
    ├── Domain
    ├── Infrastructure
    └── SharedServiceProvider.php
```

A context's own `Shared` directory holds what its aggregates have in common, such as its route files. The
top-level `Shared` is the application's foundation layer rather than a bounded context: it owns the scaffolding
commands, the Inertia base controller, middleware and the cache and jobs migrations, and it is the one place
`ldd:make:aggregate` refuses to write into.

Each context is divided into **modules**, with each module representing an aggregate root. An aggregate root is a group
of related information and behaviors that work together as a single unit. For example:

* 👤 **User**: Contains all information and logic related to users, such as authentication, profile and sessions.
* 🔑 **APIToken**: The Sanctum personal access token, owned by the context so it can belong to a uuid user.
* 💳 **Invoice**: Represents the invoicing process, including item details, totals, and payment status.

```
app
└── IdentityAndAccess
    ├── APITokens
    ├── IdentityAndAccessServiceProvider.php
    ├── Shared
    │   └── Infrastructure
    │       └── Http
    │           └── Routes
    │               ├── api.php
    │               └── web.php
    └── Users
```

An aggregate has no fixed shape. The two shipped here make the point: `Users` has all three layers, plus contracts,
events, exceptions and event handlers, while `APITokens` is one model and one migration. That is why
`ldd:make:bounded-context` creates no layer directories at all, and why `ldd:make:aggregate` generates a core and
puts the rest behind flags.

Each module follows a 3-layer architecture:

```
app
└── IdentityAndAccess
    ├── IdentityAndAccessServiceProvider.php
    └── Users
        ├── Application
        ├── Domain
        └── Infrastructure
```

🏠 **Domain**

The **Domain** layer contains the core business logic of the application. It includes the entities, value objects, and
domain services that define the rules for the root aggregate. In the **IdentityAndAccess** context, specifically for the
**User** aggregate, the **Domain** layer includes:

```
app
└── IdentityAndAccess
    ├── IdentityAndAccessServiceProvider.php
    └── Users
        └── Domain
            ├── Agent.php
            ├── Contracts
            │   └── UserRepository.php
            ├── Events
            │   ├── UserCreated.php
            │   ├── UserDeleted.php
            │   ├── UserEmailUpdated.php
            │   └── UserNameUpdated.php
            ├── Exceptions
            │   ├── UserException.php
            │   └── UserNotFound.php
            ├── PasswordValidationRules.php
            └── User.php

```

📋 **Application**

This layer contains use cases, commands, and application services, defining how the business logic is used to
fulfill the application's requirements.

```
app
└── IdentityAndAccess
    ├── IdentityAndAccessServiceProvider.php
    └── Users
        └── Application
            ├── CreateUser.php
            ├── DeleteUser.php
            ├── EventHandlers
            │   └── SendUserEmailVerification.php
            ├── FindUser.php
            ├── ResetUserPassword.php
            ├── UpdateUserPassword.php
            └── UpdateUserProfileInformation.php
```

🌐 **Infrastructure**

The layer responsible for interacting with external systems, such as databases, APIs, and file systems. It includes
repositories, external services, and infrastructure-specific configurations.

```
app
└── IdentityAndAccess
    ├── IdentityAndAccessServiceProvider.php
    └── Users
        └── Infrastructure
            ├── Http
            │   └── Controllers
            │       ├── Concerns
            │       │   ├── ConfirmsPasswords.php
            │       │   └── ConfirmsTwoFactorAuthentication.php
            │       ├── DeleteUserController.php
            │       ├── OtherBrowserSessionsController.php
            │       ├── UserProfileController.php
            │       └── UserProfilePhotoController.php
            └── Persistence
                ├── EloquentUserRepository.php
                ├── Migrations
                │   ├── 0000_00_00_000001_create_users_table.php
                │   └── 2024_12_04_123046_add_two_factor_columns_to_users_table.php
                └── UserFactory.php
```

Migrations belong to the aggregate that owns the table, not to `database/migrations`, which this starter does not
have. Each one is registered through the `$migrations` array on the context's provider, and forgetting that entry is
the quiet failure `ldd:make:aggregate --migration` exists to prevent.

This structure ensures that each part of the application is clearly defined, maintainable, and focused on its specific
domain, while following DDD and Hexagonal Architecture principles.

## 📐 Conventions

Four things surprise people arriving from stock Laravel.

**`routes/web.php` is empty on purpose.** Each context publishes its own routes through the `$routes` array on its
provider, from `{Context}/Shared/Infrastructure/Http/Routes/`. `BoundedContextServiceProvider::bootRoutes()` applies
the middleware group but *not* a URI prefix, so an `api.php` declares `Route::prefix('api')` itself.

**Aggregates are identified by uuid.** A domain event carries the identifier, so the identifier has to exist before
`save()`. `new()` assigns it up front with `HasUuids::newUniqueId()`, the same helper Eloquent's `creating` hook would
have called later.

**The aggregate root points at its own factory.** A factory living in `Infrastructure/Persistence` is not where
Laravel looks for one, so the model declares `newFactory()`. That is a deliberate Domain to Infrastructure coupling,
and the architecture rules exempt it by detecting `newFactory` rather than listing classes by hand.

**Domain events are ComplexHeart's, not Laravel's.** They implement `ComplexHeart\Domain\Contracts\Events\Event` and
use the `IsDomainEvent` trait, which supplies `eventId`, `eventName`, `payload` and `occurredOn`. They carry
identifiers and scalars, never Eloquent models. That is what makes `payload()` meaningful and `SerializesModels`
unnecessary. An aggregate records them; a use case publishes them through the `EventBus`.

## ⚠️ Disclaimer

This repository represents my personal approach to implementing Domain-Driven Design (DDD) and Hexagonal Architecture
using the Laravel framework. While I believe this structure can provide a solid foundation for many projects, it may not
fit every use case or project type.

Please note that this starter is still in early stages, and there is a lot of room for improvement. Contributions,
feedback, and suggestions are welcome as I continue to refine and expand this project.
