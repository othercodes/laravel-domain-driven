# Laravel Domain Driven

<a href="https://laravel.com/docs/13.x"><img src="https://img.shields.io/badge/Laravel-13-FF2D20.svg?style=flat&logo=laravel" alt="Laravel 13"/></a>
<a href="https://www.php.net/releases/8.4/en.php"><img src="https://img.shields.io/badge/PHP-8.4-777BB4.svg?style=flat&logo=php" alt="PHP 8.4"/></a>
<a href="https://github.com/othercodes/laravel-domain-driven/actions/workflows/test.yml"><img src="https://github.com/othercodes/laravel-domain-driven/actions/workflows/test.yml/badge.svg" alt="Test"/></a>

A Laravel 13 starter that arranges an application into bounded contexts, aggregates and layers, following
Domain-Driven Design and Hexagonal Architecture.

It comes with authentication, two-factor, session management and profiles built on Fortify with Inertia and Vue 3,
a Pest suite that enforces the layering, and `ldd:make:*` commands that scaffold new contexts and aggregates
together with the wiring they need. Sail is the supported environment, with PHP 8.4 and Node 24 pinned.

## 🤔 Why use Laravel Domain Driven?

Plenty of repositories publish a DDD directory tree. A tree is the easy part, and it is also the part that erodes
first. What this starter adds is that the structure is enforced, generated, and already applied to something real.

- **The architecture is checked, not just described.** A Pest suite fails the build when a Domain layer reaches into
  Infrastructure, or when one bounded context imports another's internals. The rules are derived from the `app/`
  listing rather than written out by hand, so a context you add tomorrow is covered the moment it exists, and every
  context already there starts forbidding it at the same time.

- **The structure is generated, and it tells you what is left.** The `ldd:make:*` commands write files. They do not
  edit files they did not create: the one exception is `ldd:make:bounded-context`, which registers its provider in
  `bootstrap/providers.php` through Laravel's own helper, so a context comes out fully wired. Everything after that
  ends with a report naming what to register and where, with the code as it would read: the repository binding, the
  migration path, the event mapping, the console command, the seeder, the route. Each of those is a step that fails
  silently when forgotten, and the report is what stops it being forgotten.

- **Nothing generated can take the application down.** A command that appends to a provider is a command that can
  corrupt one, and a provider is loaded from `bootstrap/providers.php`, so a bad edit takes `artisan` with it. These
  commands only ever create files, which is why the worst a bad name can do is leave one file that does not compile
  in its own directory, loaded by nothing. That is the position Laravel's own `make:*` commands take.

- **It ships applied, not as an example.** Registration, login, two-factor, session management and profiles are
  already rearranged into contexts, aggregates and layers. You read the pattern on features that work, rather than
  on a `Foo` aggregate you delete on day one.

- **Bounded contexts are declarative.** A context provider lists what it owns in `$bindings`, `$events`,
  `$commands`, `$migrations` and `$routes`, and ComplexHeart boots it. Nothing is registered by hand in a boot
  method, so a context stays something you can read in one screen.

- **Laravel stays Laravel.** The aggregate root is an Eloquent model, and Fortify, Inertia, queues, factories and
  the rest work exactly as their own documentation says. This is a Laravel application rearranged, not a framework
  built on top of one.

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
composer dev
```

That runs the queue worker, the log tail and Vite together inside the Sail container, so Docker is the only thing it
needs on your host. There is no `artisan serve` among them because the container already serves the application on
`APP_PORT`, and Vite is reachable on `VITE_PORT` with HMR.

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
exceptions, and then prints the binding to add to the context's service provider, which is what
makes the contract resolvable at all. Everything else is opt in, because real aggregates rarely
need every layer:

| Flag | Adds |
|---|---|
| `--migration` | A migration, and the `$migrations` entry to add for it |
| `--factory` | A model factory in `Domain/Factories`, routed through the aggregate's `new()` |
| `--seeder` | A seeder in the context, and the `DatabaseSeeder` entry to add for it |
| `--events` | A `Created` domain event, recorded by `new()` and published by you |
| `--requests` | A form request |
| `--web` | An Inertia controller, rendering through `InertiaController`, and a resource |
| `--api` | An API controller, and a resource |
| `--all` | All of the above |

Four more take a name instead of being on or off, and can be repeated. `--all` does not cover them:

| Flag | Adds |
|---|---|
| `--mail=InvoicePaid` | A mailable in `Application/Mail` |
| `--job=RebuildIndex` | A queued job in `Application/Jobs` |
| `--notification=InvoiceDue` | A notification in `Application/Notifications` |
| `--command=SyncInvoices` | An Artisan command in `Infrastructure/Console/Commands`, and the `$commands` entry to add for it |

Either controller flag also writes an `{Aggregate}Resource`, because both of them publish and neither should hand
out the model itself. It starts closed, with `id` and nothing else, so an attribute reaches the outside because you
listed it rather than because a migration added it. The API controller returns the resource and Laravel wraps it in
a `data` key; the Inertia controller passes `resolve($request)`, since the prop name is already the envelope there
and Inertia would otherwise call `toArray()` directly, skipping the filtering that `when()` relies on.

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

The handler is the one that needs declaring. It goes in the provider's `$events`, and one that is
missing from there is simply never called, which is why the command prints the mapping rather than
leaving you to remember it. It prints rather than appends because `$events` is keyed by the event
class: two entries under one key leave PHP keeping the last and dropping the first, without a word,
and which handler should survive is not a decision a generator gets to make.

Everything the report prints is written out in full, never as an import. The file it goes into keeps
an import list of its own, and two imports resolving to one short name is a compile-time fatal.
Pasted fully qualified, a line cannot collide with anything, and `pint` will shorten it afterwards
if it can do so safely.

All four commands only ever add. Run one again with a flag you skipped and it writes what is missing,
leaves every file already on disk alone, since your migration may have been applied and your provider
carries wiring no stub knows about, and prints again what is still to be registered.

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
            ├── Factories
            │   └── UserFactory.php
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
                └── Seeders
                    └── UserSeeder.php
```

Migrations belong to the aggregate that owns the table, not to `database/migrations`, which this starter does not
have. Each directory is registered through the `$migrations` array on the context's provider, and a directory that
is not listed there is never migrated, with `migrate` reporting nothing to do. `ldd:make:aggregate --migration`
prints the entry to add for exactly that reason.

Seeders sit beside those migrations, one per aggregate, and the factory lives in `Domain/Factories` next to the
aggregate it builds. The root `DatabaseSeeder` is the exception: it stays in the `Database\Seeders` namespace, which
`composer.json` points at `app/Shared/Infrastructure/Persistence/Seeders`, because `db:seed` resolves that name and
nothing else when given no `--class`. Every aggregate seeder you add has to be listed there by hand, in the order it
should run.

This structure ensures that each part of the application is clearly defined, maintainable, and focused on its specific
domain, while following DDD and Hexagonal Architecture principles.

## 📐 Conventions

Six things surprise people arriving from stock Laravel.

**`routes/web.php` is empty on purpose.** Each context publishes its own routes through the `$routes` array on its
provider, from `{Context}/Shared/Infrastructure/Http/Routes/`. `BoundedContextServiceProvider::bootRoutes()` applies
the middleware group but *not* a URI prefix, so an `api.php` declares `Route::prefix('api')` itself.

**The aggregates this starter generates are identified by uuid, and built through `new()`.** A domain event carries
the identifier, so the identifier has to exist before `save()`. `new()` assigns it up front with
`HasUuids::newUniqueId()`, the same helper Eloquent's `creating` hook would have called later, and the aggregate
declares that with `BuildsFromAttributes` so a factory can rely on the method being there. An aggregate adopted from
elsewhere need not follow it: `APIToken` extends Sanctum's `PersonalAccessToken` and keeps its own key.

**The aggregate root points at its own factory, and the factory lives in `Domain/Factories`.** Laravel only looks
in `Database\Factories`, so the model declares `newFactory()`. Keeping the factory in `Domain` keeps that pointer
inside one layer, which is why `domain does not depend on infrastructure` needs no exemption. Nothing new is let
into `Domain` by it: the aggregate root is already an Eloquent model.

Factories extend `AggregateFactory`, not Eloquent's `Factory`, so that `make()` and `create()` go through the
aggregate's `new()`. One written against `Factory` instead would build instances with no identifier and no recorded
event, so this is not left to whoever writes the next factory: an architecture rule fails the suite for anything in
`Domain/Factories` that does not extend the base class.

**Domain events are ComplexHeart's, not Laravel's.** They implement `ComplexHeart\Domain\Contracts\Events\Event` and
use the `IsDomainEvent` trait, which supplies `eventId`, `eventName`, `payload` and `occurredOn`. They carry
identifiers and scalars, never Eloquent models. That is what makes `payload()` meaningful and `SerializesModels`
unnecessary. An aggregate records them; a use case publishes them through the `EventBus`.

**Laravel's own generators need the full class name.** The flags above cover the usual ones. For anything else,
`make:policy`, `make:observer`, `make:rule` and the rest, name the class in full and it lands where you say:

```bash
./vendor/bin/sail artisan make:policy 'App\Billing\Invoices\Infrastructure\Http\Policies\InvoicePolicy'
```

Drop the `App\` and you get `app/Policies/Billing/...` instead, because each generator prepends its own namespace to
a name that does not already start at the root. `make:seeder` and `make:factory` ignore the name either way and
always write into `database/`.

**`DatabaseSeeder` is the one file that reaches into every context.** Seeders live with their aggregate under
`Infrastructure/Persistence/Seeders`, and only run once `DatabaseSeeder` lists them, in the order it lists them.
`--seeder` writes the class but leaves the entry to you, since a seeder whose reference data another one depends on
has to go first. Sample data goes in `$fixtures` instead, which never runs in production.

## ⚠️ Disclaimer

This repository represents my personal approach to implementing Domain-Driven Design (DDD) and Hexagonal Architecture
using the Laravel framework. While I believe this structure can provide a solid foundation for many projects, it may not
fit every use case or project type.

Please note that this starter is still in early stages, and there is a lot of room for improvement. Contributions,
feedback, and suggestions are welcome as I continue to refine and expand this project.
