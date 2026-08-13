<?php

use App\Shared\Infrastructure\Console\Support\SourceFile;

/*
 * Every case here is one the scaffolding commands used to get wrong by
 * searching the text: a name in a comment, in a docblock or inside a string
 * reads exactly like the real thing.
 */

test('it tells a call from a mention of the same name', function () {
    $source = SourceFile::of(<<<'PHP'
    <?php
    class Widget
    {
        /** Call registerDomainEvent here when this grows up. */
        public static function new(): self
        {
            // $widget->registerDomainEvent(...);
            $note = 'remember to registerDomainEvent';

            return new self;
        }
    }
    PHP);

    expect($source->calls('registerDomainEvent'))->toBeFalse();

    expect(SourceFile::of('<?php $w->registerDomainEvent($e);')->calls('registerDomainEvent'))->toBeTrue();
});

test('it finds a declared method and ignores one only spoken about', function () {
    $source = SourceFile::of(<<<'PHP'
    <?php
    class Widget
    {
        // protected static function newFactory(): WidgetFactory {}
        public function other(): void {}
    }
    PHP);

    expect($source->declaresMethod('newFactory'))->toBeFalse()
        ->and($source->declaresMethod('other'))->toBeTrue();
});

test('it resolves class references through the imports', function () {
    $source = SourceFile::of(<<<'PHP'
    <?php
    use App\Billing\Invoices\Infrastructure\Http\Controllers\InvoiceController;
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    PHP);

    // Written short, asked for by its full name: the file's own use statement
    // is what connects the two, and a substring search cannot follow it.
    expect($source->references('App\Billing\Invoices\Infrastructure\Http\Controllers\InvoiceController'))->toBeTrue()
        ->and($source->references('App\Billing\Invoices\Infrastructure\Http\Controllers\OtherController'))->toBeFalse();
});

test('it sees an implements clause, which is not a class reference', function () {
    $queued = SourceFile::of(<<<'PHP'
    <?php
    use Illuminate\Contracts\Queue\ShouldQueue;
    final readonly class NotifyAccounting implements ShouldQueue {}
    PHP);

    $plain = SourceFile::of(<<<'PHP'
    <?php
    use Illuminate\Contracts\Queue\ShouldQueue;
    final readonly class NotifyAccounting {}
    PHP);

    // The second file imports the interface without implementing it, which is
    // exactly the half-done state a run left behind before.
    expect($queued->implementsInterface('Illuminate\Contracts\Queue\ShouldQueue'))->toBeTrue()
        ->and($plain->implementsInterface('Illuminate\Contracts\Queue\ShouldQueue'))->toBeFalse();
});

test('it finds a call made through the nullsafe operator', function () {
    expect(SourceFile::of('<?php $w?->publishDomainEvents($bus);')->calls('publishDomainEvents'))->toBeTrue();
});

test('it finds a property that is not the first of its statement', function () {
    // One statement, two properties: asking for the second used to answer as
    // if it were not declared at all.
    $source = SourceFile::of('<?php class W { protected $guarded = [], $table = "widgets"; }');

    expect($source->propertyString('table'))->toBe('widgets');
});

test('it reads the table a model declares', function () {
    expect(SourceFile::of('<?php class W { protected $table = "widgets"; }')->propertyString('table'))->toBe('widgets')
        ->and(SourceFile::of('<?php class W {}')->propertyString('table'))->toBeNull();
});

test('it lists the keys of an array property and skips commented ones', function () {
    $source = SourceFile::of(<<<'PHP'
    <?php
    namespace App\Billing;
    use App\Billing\Invoices\Domain\Events\InvoiceCreated;
    class BillingServiceProvider
    {
        protected array $events = [
            InvoiceCreated::class => NotifyAccounting::class,
            // InvoicePaid::class => Ghost::class,
        ];
    }
    PHP);

    // The commented entry is what refused the command outright before.
    expect($source->propertyKeys('events'))
        ->toBe(['App\Billing\Invoices\Domain\Events\InvoiceCreated']);
});

test('it reads the migration paths out of a concatenation', function () {
    $source = SourceFile::of(<<<'PHP'
    <?php
    class P
    {
        protected array $migrations = [
            __DIR__.'/Invoices/Infrastructure/Persistence/Migrations',
        ];
    }
    PHP);

    expect($source->propertyStrings('migrations'))
        ->toBe(['/Invoices/Infrastructure/Persistence/Migrations']);
});

test('it lists returned providers however they are written', function () {
    $qualified = SourceFile::of(<<<'PHP'
    <?php
    return [
        App\Shared\SharedServiceProvider::class,
    ];
    PHP);

    $imported = SourceFile::of(<<<'PHP'
    <?php
    use App\Shared\SharedServiceProvider;
    return [
        SharedServiceProvider::class,
    ];
    PHP);

    // Laravel generates the first shape and the commands write the second.
    // Both name the same class, which is the whole point of resolving.
    expect($qualified->returnedClasses())->toBe(['App\Shared\SharedServiceProvider'])
        ->and($imported->returnedClasses())->toBe(['App\Shared\SharedServiceProvider']);
});

test('it says whether it could read the file at all', function () {
    // "I could not read it" and "it is not there" lead to opposite decisions
    // in a guard against writing something twice.
    expect(SourceFile::of('<?php class Broken {')->parsed())->toBeFalse()
        ->and(SourceFile::of('<?php class Fine {}')->parsed())->toBeTrue()
        ->and(SourceFile::at('/no/such/file.php')->parsed())->toBeTrue();
});

test('a file that does not parse answers no to everything', function () {
    $source = SourceFile::of('<?php class Broken {');

    expect($source->calls('anything'))->toBeFalse()
        ->and($source->declaresMethod('anything'))->toBeFalse()
        ->and($source->references('Anything'))->toBeFalse()
        ->and($source->propertyString('table'))->toBeNull()
        ->and($source->propertyKeys('events'))->toBe([])
        ->and($source->returnedClasses())->toBe([]);
});

test('a file that is not there answers no to everything', function () {
    expect(SourceFile::at('/no/such/file.php')->returnedClasses())->toBe([]);
});
