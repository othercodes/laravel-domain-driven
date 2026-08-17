<?php

declare(strict_types=1);

/*
 * Kept in the Database\Seeders namespace on purpose, even though the file
 * lives under app/: `db:seed` with no --class resolves that name and nothing
 * else. composer.json points the namespace here instead of at database/.
 */

namespace Database\Seeders;

use App\IdentityAndAccess\Users\Infrastructure\Persistence\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Class DatabaseSeeder
 *
 * The one place that knows the order seeders run in. Each aggregate owns its
 * own seeder under {Context}/{Aggregates}/Infrastructure/Persistence/Seeders,
 * and gets listed here; ldd:make:aggregate --seeder writes the class and says
 * to add it, rather than appending it, because the order is a decision.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Reference data every environment needs, in dependency order.
     *
     * @var list<class-string<Seeder>>
     */
    private array $seeders = [];

    /**
     * Fixtures, which exist so somebody can log in and click around. Seeding
     * a known account into production is how a starter kit ships a back door,
     * so these are held apart rather than guarded one by one.
     *
     * @var list<class-string<Seeder>>
     */
    private array $fixtures = [
        UserSeeder::class,
    ];

    public function run(): void
    {
        $this->call($this->seeders);

        if (App::environment('local', 'development', 'testing')) {
            $this->call($this->fixtures);
        }
    }
}
