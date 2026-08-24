---
paths:
  - tests/**
---

# Tests

## Run them through Sail

`./vendor/bin/sail test`, and `--parallel` works: the suite runs across 16
processes. Pest is configured with `RefreshDatabase`.

## The arch suite derives its rules from the app/ listing

`tests/Arch/ArchTest.php` never names a context or an aggregate by hand, so one
added today is covered today. Anything added there must keep that property.

## A test that drives ldd:make:* generates into a copy of the application

`scaffold_into_a_copy_of_the_app()` in `tests/Pest.php` copies `app/` to a
per-process temp directory and points the app and bootstrap paths at it, so the
commands never write into the real `app/` or `bootstrap/providers.php`. Call it
from `beforeEach` and delete the copy in `afterEach`. Generating into the real
`app/` breaks `--parallel`, because the arch suite scans that directory while
the generator test is creating and removing directories inside it.

## Assert that generated classes load, not only that they parse

A missing import parses. `php -l` cannot see one; `class_exists()` can, because
PHP resolves parents, interfaces and traits at class-load time.
