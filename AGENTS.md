# Laravel Domain-Driven

A Laravel application arranged into bounded contexts, aggregates and layers. Six
things below are not true of an ordinary Laravel application, and each one is a
mistake an agent makes here on its first try.

`laravel/boost` ships with this project. Running `php artisan boost:install`
appends a `<laravel-boost-guidelines>` block below, along with an MCP server and
a searchable Laravel documentation index. That block is generic Laravel advice
and is regenerated on every run; everything above it is this project's own and
is left alone. **Where the two disagree, this section wins.** The six points
below name the disagreements.

## Run everything through Sail

```bash
./vendor/bin/sail artisan ...      ./vendor/bin/sail test
./vendor/bin/sail bin pint         ./vendor/bin/sail bin phpstan analyse
```

The application runs in Docker and the host PHP is a different version, or
absent. This overrides Boost's `php artisan ...` and `vendor/bin/pint ...`.

## Scaffold with `ldd:make:*`, not `make:model`

There is no `app/Models`. An aggregate is thirteen files across three layers,
and one command writes them consistently:

```bash
./vendor/bin/sail artisan ldd:make:bounded-context Billing bil --web
./vendor/bin/sail artisan ldd:make:aggregate Billing Invoice --migration --factory --events
./vendor/bin/sail artisan ldd:make:use-case Billing Invoice CreateInvoice --publishes
./vendor/bin/sail artisan ldd:make:event-handler Billing Invoice NotifyAccounting
```

Run `--help` on any of them for the full flag list. This overrides Boost's "use
`php artisan make:` commands to create new files" and its model-creation advice.

## The generators print what to register. Register it.

Only `ldd:make:bounded-context` wires anything. Every other command ends with a
report naming a file, a property and the line to add, fully qualified. Read that
report and paste each entry into the file it names, then run Pint. Nothing works
until you do: an unregistered repository throws on first resolve, an
unregistered migrations directory means `migrate` reports nothing to run, and an
unregistered event handler is simply never called.

The report says nothing about whether the entry is already there, because the
command never read the file. Check before pasting. Where an entry has a key,
`$events` above all, a second entry under the same key replaces the first in
silence: list both handlers in an array instead.

## Tables carry their context's code

There is no `users` table here, there is `iaa_users`. Every context declares a
short code on its service provider, `public string $tablePrefix`, and every
table it owns starts with it: `iaa_`, `shd_`, and whatever a new context picks.

The code is asked for when the context is created, `ldd:make:bounded-context
Billing bil`, never derived: which abbreviation reads back to a context is a
judgement. `ldd:make:aggregate` then reads the property and names the table for
you, so this only matters when you write a migration by hand or point a model
at a table.
Both halves have to agree: `Schema::create('trn_exercises')` and
`protected $table = 'trn_exercises'`. A framework table named in config, a
queue or a session table, counts too.

## Dependencies point inwards

Domain is the centre. Application depends on Domain. Infrastructure depends on
both. To reach a database, a queue or an HTTP client from Domain, declare an
interface in `Domain/Contracts` and implement it in `Infrastructure`, then
register the binding in the context's provider.

`tests/Arch/ArchTest.php` enforces this and derives its rules from the `app/`
listing, so a context added today is covered today. Run
`./vendor/bin/sail test tests/Arch` after moving code between layers.

## Inertia pages live under `resources/templates`

Pages are at `resources/templates/tailwindcss/js/Pages`, per `vite.config.js`.
`resources/js/Pages` does not exist here, which is what Boost's Inertia
guideline assumes.

## Before you edit

`.ai/rules/index.md` maps paths to the rules that apply to them. Read the rows
matching what you are about to touch.
