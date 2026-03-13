# Laravel Domain Driven

<a href="https://laravel.com/docs/11.x"><img src="https://img.shields.io/badge/Laravel-11-FF2D20.svg?style=flat&logo=laravel" alt="Laravel 11"/></a>
<a href="https://www.php.net/releases/8.2/en.php"><img src="https://img.shields.io/badge/PHP-8.2-777BB4.svg?style=flat&logo=php" alt="PHP 8.2"/></a>
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

**Laravel Domain Driven** includes the core features provided by **Laravel Jetstream**, but organized using 
**Domain-Driven Design (DDD)** and **Hexagonal Architecture**. Key features include:

- **Authentication**: User registration, login, password reset, email verification, and more, powered by **Laravel
  Fortify**.
- **Authorization**: Role-based access control with user roles and permissions.
- **Two-Factor Authentication (2FA)**: Extra layer of security for user accounts, integrated with **Laravel Fortify**.
- **Session Management**: Manage active sessions and log users out of other devices.
- **Profile Management**: Users can update their profile information, including email and password.
- **Inertia.js with Vue 3**: Provides a modern, single-page app experience with a clean structure for building
  interactive UIs, while still leveraging the full power of Laravel on the backend.
- **Tailwind CSS**: Utility-first CSS framework that makes it easy to create responsive and customized designs without
  writing custom CSS.
- **Laravel Sanctum**: Simple token-based API authentication system, allowing you to securely authenticate and manage
  user sessions for SPAs and mobile applications.
- **Laravel Sail**: Lightweight Docker environment for developing Laravel applications locally, simplifying setup and
  development.
- **Laravel Pint**: A zero-config PSR-12 compliant PHP code style fixer, ensuring consistent coding standards across
  your project.
- **Larastan**: Static analysis tool that helps detect potential issues in your code, improving code quality and
  reducing bugs.
- **Pest**: A modern testing framework for PHP that makes testing simpler, faster, and more readable, providing a fluent
  and expressive syntax for writing tests.

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

# Install frontend dependencies and build assets
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Link the public/storage directory to the storage/app/public directory
./vendor/bin/sail artisan storage:link

# Run tests to verify everything works
./vendor/bin/sail test
```

For development with HMR, queue worker, and log tailing:

```bash
composer dev
```

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
│   └── IdentityAndAccessServiceProvider.php
└── Shared
    └── SharedServiceProvider.php
```

Each context is divided into **modules**, with each module representing an aggregate root. An aggregate root is a group
of related information and behaviors that work together as a single unit. For example:

* 👤 **User**: Contains all information and logic related to users, such as authentication, profile, and roles.
* 🛒 **Order**: Manages the order details, status, and so on.
* 💳 **Invoice**: Represents the invoicing process, including item details, totals, and payment status.

```
app
└── IdentityAndAccess
    ├── IdentityAndAccessServiceProvider.php
    └── Users
```

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
                └── UserFactory.php
```

This structure ensures that each part of the application is clearly defined, maintainable, and focused on its specific
domain, while following DDD and Hexagonal Architecture principles.

## ⚠️ Disclaimer

This repository represents my personal approach to implementing Domain-Driven Design (DDD) and Hexagonal Architecture
using the Laravel framework. While I believe this structure can provide a solid foundation for many projects, it may not
fit every use case or project type.

Please note that this starter is still in early stages, and there is a lot of room for improvement. Contributions,
feedback, and suggestions are welcome as I continue to refine and expand this project.
