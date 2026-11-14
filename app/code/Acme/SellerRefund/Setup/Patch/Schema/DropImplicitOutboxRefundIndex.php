<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Setup\Patch\Schema;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;

/**
 * Remove the outbox refund_id index that MariaDB/MySQL created by itself for the foreign key.
 *
 * Before MP_REFUND_OUTBOX_REFUND_ID_OPERATION_STATE was declared, the database created an
 * undeclared index named after the foreign key. The declared composite index now serves the
 * foreign key and the outbox lookups, so the old one is a redundant duplicate. Declarative
 * schema cannot drop an index it never declared, hence this patch. Fresh installs never have
 * the index, and the patch does nothing there.
 */
class DropImplicitOutboxRefundIndex implements SchemaPatchInterface
{
    private const TABLE = 'mp_refund_outbox';
    private const IMPLICIT_INDEX = 'MP_REFUND_OUTBOX_REFUND_ID_MP_REFUND_ENTITY_ID';
    private const DECLARED_INDEX = 'MP_REFUND_OUTBOX_REFUND_ID_OPERATION_STATE';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Drop the implicit index when the declared composite index is in place.
     *
     * @return $this
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);
        $indexes = array_change_key_case($connection->getIndexList($table), CASE_UPPER);

        if (isset($indexes[self::IMPLICIT_INDEX], $indexes[self::DECLARED_INDEX])) {
            $connection->dropIndex($table, self::IMPLICIT_INDEX);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
