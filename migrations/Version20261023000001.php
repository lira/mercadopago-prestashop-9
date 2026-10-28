<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use MercadoPago\Repository\PreferenceGuardRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class Version20261023000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable Checkout Pro snapshots and reconciliation state to mp_transactions';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(_DB_PREFIX_ . 'mp_transactions');
        $operations = [];
        foreach (PreferenceGuardRepository::columns() as $name => $definition) {
            if ($table->hasColumn($name)) {
                continue;
            }
            $operations[] = 'ADD COLUMN `' . $name . '` ' . $definition;
        }
        foreach (['uk_mp_guard_preference' => 'guard_preference_id', 'uk_mp_guard_review' => 'guard_review_payment_id'] as $index => $column) {
            if (!$table->hasIndex($index)) {
                $operations[] = 'ADD UNIQUE INDEX `' . $index . '` (`' . $column . '`)';
            }
        }
        if ($operations !== []) {
            $this->addSql('ALTER TABLE `' . _DB_PREFIX_ . 'mp_transactions` ' . implode(', ', $operations));
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(_DB_PREFIX_ . 'mp_transactions');
        $operations = [];
        foreach (['uk_mp_guard_preference', 'uk_mp_guard_review'] as $index) {
            if ($table->hasIndex($index)) {
                $operations[] = 'DROP INDEX `' . $index . '`';
            }
        }
        foreach (array_keys(PreferenceGuardRepository::columns()) as $column) {
            if ($table->hasColumn($column)) {
                $operations[] = 'DROP COLUMN `' . $column . '`';
            }
        }
        if ($operations !== []) {
            $this->addSql('ALTER TABLE `' . _DB_PREFIX_ . 'mp_transactions` ' . implode(', ', $operations));
        }
    }
}
