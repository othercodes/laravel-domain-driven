---
paths:
  - app/*/*ServiceProvider.php
---

# Bounded context providers

## Everything is declared, nothing is booted by hand

A context provider extends ComplexHeart's `BoundedContextServiceProvider` and
declares `$bindings`, `$events`, `$commands` and `$migrations`, plus `$routes`
once the context has a route file. The base class boots all of them. Adding a
`boot()` method here is a sign something belongs in one of the arrays instead.

A context created without `--web` or `--api` carries `$routes` as a commented
example. Replace that comment with a real property rather than uncommenting it:
the example names a `web.php` the run may not have created.

## The context stamps its code on its tables

`public string $tablePrefix` is the short code every table this context owns
starts with. `ldd:make:aggregate` reads it by reflection when it names a table,
so an aggregate lands on `<prefix>_<plural>` without anyone asking.

It is asked for when the context is created, two or three lowercase letters,
and never derived: which abbreviation reads back to a context is a judgement.
Initials usually, `IdentityAndAccess` to `iaa`, a contraction where the name is
one word, `Shared` to `shd`, and sometimes neither, where a context is known in
the business by another name. Somebody reading `trn_exercises` in a database
client has to be able to name the context without asking.

A test walks the schema and holds that every table carries a declared code.

## This is where the generator reports land

`ldd:make:aggregate` and `ldd:make:event-handler` print entries for these arrays
rather than appending them, because the file may hold hand-written wiring the
command cannot read. Paste what they print, fully qualified, then run
`./vendor/bin/sail bin pint` to shorten the names.

`$events` is keyed by the event class. A second entry under a key that already
has one replaces the first with no error and the first handler stops running.
List both instead:

```php
\App\Billing\Invoices\Domain\Events\InvoiceCreated::class => [
    \App\Billing\Invoices\Application\EventHandlers\NotifyAccounting::class,
    \App\Billing\Invoices\Application\EventHandlers\UpdateLedger::class,
],
```

## A missing entry fails quietly

An unbound repository throws only when something first resolves it. An
unregistered migrations directory makes `migrate` report nothing to run. An
undeclared route file answers 404. None of them fails at boot.
