---
paths:
  - app/*/*/Application/**
---

# Application layer

## Change an aggregate through the aggregate

Call the methods the aggregate exposes. `forceFill(...)` from here bypasses the
domain rules the method carries, and `tests/Arch/ArchTest.php` fails on it
anywhere under an `Application` directory.

## Reach the domain through the repository contract

Type the constructor against `Domain\Contracts\<Aggregate>Repository`, never the
Eloquent implementation. That is what the arch rules check and what lets a test
substitute it.

## Queued handlers need nothing extra

Domain events are published inside `DB::transaction`, so a queued listener could
otherwise run before the commit. Every connection in `config/queue.php` sets
`after_commit => true`, which covers listeners, jobs, mailables and
notifications at once. Plain `ShouldQueue` is correct here; the interface does
not need to say `AfterCommit`.
