---
paths:
  - app/*/*/Domain/**
---

# Domain layer

## Reach the outside through an interface declared here

Domain is the innermost layer: it may use `App\Shared\Domain` and nothing else
from the application. To read a database, dispatch a job or call an HTTP API,
declare an interface in `Domain/Contracts`, implement it in `Infrastructure`,
and bind the two in the context's service provider.

`tests/Arch/ArchTest.php` fails on an import of `Application` or
`Infrastructure` from here, including in `app/Shared/Domain`.

## The aggregate root records events; it never publishes them

`new()` assigns the identifier and calls `registerDomainEvent(...)`, so the
event carries the id before the row exists. Publishing belongs to a use case:
`ldd:make:use-case <Context> <Aggregate> <Name> --publishes` writes the idiom,
which saves and publishes inside one `DB::transaction`.

An aggregate that records events and has no use case publishing them loses them
when the instance goes out of scope, silently.

## Factories live beside the aggregate

`Domain/Factories/<Aggregate>Factory`, extending `App\Shared\Domain\AggregateFactory`,
which overrides `newModel()` to call the aggregate's `new()`. That is the hook
`make()` and `create()` go through, so a factory built this way assigns the
identifier and records the creation event like any other caller. Do not override
`newModel()` again in a generated factory.

Laravel would not find a factory outside `Database\Factories`, so the model
points at it through `newFactory()`. Generate both with
`ldd:make:aggregate ... --factory`.
