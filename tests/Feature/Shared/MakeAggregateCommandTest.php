<?php

use Illuminate\Support\Facades\File;

/*
 * The command writes into app/ and edits the context provider, so every test
 * starts from a freshly generated context and removes it afterwards.
 */
beforeEach(function () {
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);

    $this->artisan('ldd:make:bounded-context', ['name' => 'Billing'])->assertSuccessful();
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);
    File::deleteDirectory(app_path('Billing'));
});

test('it generates only the core by default', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])
        ->assertSuccessful();

    expect(app_path('Billing/Invoices/Domain/Invoice.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Domain/Contracts/InvoiceRepository.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Domain/Exceptions/InvoiceNotFound.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Infrastructure/Persistence/EloquentInvoiceRepository.php'))->toBeFile();

    expect(app_path('Billing/Invoices/Domain/Events'))->not->toBeDirectory()
        ->and(app_path('Billing/Invoices/Infrastructure/Persistence/Migrations'))->not->toBeDirectory()
        ->and(app_path('Billing/Invoices/Infrastructure/Http'))->not->toBeDirectory();
});

test('it binds the repository in the context provider', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])
        ->assertSuccessful();

    expect(File::get(app_path('Billing/BillingServiceProvider.php')))
        ->toContain('use App\Billing\Invoices\Domain\Contracts\InvoiceRepository;')
        ->toContain('use App\Billing\Invoices\Infrastructure\Persistence\EloquentInvoiceRepository;')
        ->toContain('InvoiceRepository::class => EloquentInvoiceRepository::class,');
});

test('it registers the migration path only when a migration is generated', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])->assertSuccessful();

    expect(File::get(app_path('Billing/BillingServiceProvider.php')))
        ->not->toContain('Invoices/Infrastructure/Persistence/Migrations');

    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Receipt', '--migration' => true])
        ->assertSuccessful();

    expect(File::get(app_path('Billing/BillingServiceProvider.php')))
        ->toContain("__DIR__.'/Receipts/Infrastructure/Persistence/Migrations',");
});

test('the aggregate records its creation only with the events flag', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])->assertSuccessful();
    expect(File::get(app_path('Billing/Invoices/Domain/Invoice.php')))
        ->not->toContain('registerDomainEvent');

    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Receipt', '--events' => true])
        ->assertSuccessful();

    expect(app_path('Billing/Receipts/Domain/Events/ReceiptCreated.php'))->toBeFile();
    expect(File::get(app_path('Billing/Receipts/Domain/Receipt.php')))
        ->toContain('registerDomainEvent(ReceiptCreated::new($receipt->id))');
});

test('the factory is routed through the aggregate factory method', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice', '--factory' => true])
        ->assertSuccessful();

    expect(File::get(app_path('Billing/Invoices/Infrastructure/Persistence/InvoiceFactory.php')))
        ->toContain('return Invoice::new($attributes);');

    // Laravel would not find a factory outside Database\Factories, so the
    // model has to point at it explicitly.
    expect(File::get(app_path('Billing/Invoices/Domain/Invoice.php')))
        ->toContain('protected static function newFactory(): InvoiceFactory');
});

test('it pluralises the aggregate directory and singularises the class', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'invoices'])
        ->assertSuccessful();

    expect(app_path('Billing/Invoices/Domain/Invoice.php'))->toBeFile();
});

test('it refuses an unknown bounded context', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Nope', 'name' => 'Invoice'])
        ->assertFailed();
});

test('it refuses to overwrite an existing aggregate', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice'])->assertFailed();
});

test('all generates every optional piece', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Billing', 'name' => 'Invoice', '--all' => true])
        ->assertSuccessful();

    expect(app_path('Billing/Invoices/Domain/Events/InvoiceCreated.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Infrastructure/Persistence/InvoiceFactory.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Infrastructure/Http/Requests/StoreInvoiceRequest.php'))->toBeFile()
        ->and(app_path('Billing/Invoices/Infrastructure/Http/Controllers/API/InvoiceController.php'))->toBeFile()
        ->and(File::glob(app_path('Billing/Invoices/Infrastructure/Persistence/Migrations/*_create_invoices_table.php')))
        ->toHaveCount(1);
});
