<?php

declare(strict_types=1);

/*
 * @copyright 2023 Akeneo SAS (https://www.akeneo.com)
 * @license https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Akeneo\Platform\Installer\Application\PurgeInstance;

use Akeneo\Platform\Installer\Domain\Query\FindTablesInterface;
use Akeneo\Platform\Installer\Domain\Service\DatabasePurgerInterface;

class PurgeInstanceHandler
{
    private const TABLE_TO_KEEP = [];

    public function __construct(
        private readonly FindTablesInterface $findTables,
        private readonly DatabasePurgerInterface $databasePurger
    ) {
    }

    public function handle(PurgeInstanceCommand $command): void
    {
        $tables = $this->findTables->all();
        $tablesToPurge = array_filter($tables, fn (string $table) => !in_array($table, self::TABLE_TO_KEEP));

        $this->databasePurger->purge($tablesToPurge);
    }
}
