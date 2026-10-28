<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Plugin\Setup;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\Declaration\Schema\Diff\Diff;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Magento\Framework\Setup\Declaration\Schema\Dto\Table;
use Magento\Framework\Setup\Declaration\Schema\Dto\TableElementInterface;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropElement;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropReference;
use Magento\Framework\Setup\Declaration\Schema\Operations\DropTable;

/**
 * Keeps the tables, columns, indexes and keys of a disabled EU Withdrawal add-on through setup:upgrade.
 *
 * Magento builds the target schema from enabled modules only, but allows a drop for anything listed in the
 * db_schema_whitelist.json of any registered module, enabled or not. A Pro add-on that is disabled while its
 * code stays installed would therefore lose its data on the next upgrade. This plugin refuses those drops for
 * mm_eu_withdrawal_* elements that a disabled module whitelists; the element comes back into use when the
 * add-on is enabled again. Removing the add-on's package leaves the tables in place as well.
 */
class KeepDisabledAddonSchema
{
    private const TABLE_PREFIX = 'mm_eu_withdrawal_';
    private const WHITELIST_FILE = '/etc/' . Diff::GENERATED_WHITELIST_FILE_NAME;
    private const DROP_OPERATIONS = [
        DropTable::OPERATION_NAME,
        DropElement::OPERATION_NAME,
        DropReference::OPERATION_NAME,
    ];

    /** @var array<string, array<string, mixed>>|null Whitelists of disabled modules, merged, keyed by table */
    private ?array $disabledWhitelist = null;

    /**
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param ModuleListInterface $moduleList
     * @param File $file
     * @param Json $json
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ModuleListInterface $moduleList,
        private readonly File $file,
        private readonly Json $json,
    ) {
    }

    /**
     * Refuse a drop the core allowed when the element belongs to a disabled module.
     *
     * @param Diff $subject
     * @param bool $result
     * @param ElementInterface $object
     * @param string $operation
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCanBeRegistered(Diff $subject, bool $result, ElementInterface $object, $operation): bool
    {
        if (!$result || !in_array($operation, self::DROP_OPERATIONS, true)) {
            return $result;
        }
        $table = $object instanceof TableElementInterface ? $object->getTable() : $object;
        if (!$table instanceof Table || !str_starts_with($table->getNameWithoutPrefix(), self::TABLE_PREFIX)) {
            return $result;
        }
        return !$this->isWhitelistedByDisabledModule($table->getNameWithoutPrefix(), $object);
    }

    /**
     * Same lookup as the core whitelist check, against disabled modules only.
     *
     * @param string $table
     * @param ElementInterface $object
     * @return bool
     */
    private function isWhitelistedByDisabledModule(string $table, ElementInterface $object): bool
    {
        $whitelist = $this->disabledWhitelist();
        if (!$object instanceof TableElementInterface) {
            return isset($whitelist[$table]);
        }
        $name = $object instanceof Index || $object instanceof Constraint
            ? $object->getNameWithoutPrefix()
            : $object->getName();
        return isset($whitelist[$table][$object->getElementType()][$name]);
    }

    /**
     * Whitelists of the registered modules that are disabled, merged.
     *
     * @return array<string, array<string, mixed>>
     */
    private function disabledWhitelist(): array
    {
        if ($this->disabledWhitelist !== null) {
            return $this->disabledWhitelist;
        }
        $this->disabledWhitelist = [];
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $module => $path) {
            $file = $path . self::WHITELIST_FILE;
            if ($this->moduleList->has($module) || !$this->file->isExists($file)) {
                continue;
            }
            $this->disabledWhitelist = array_replace_recursive(
                $this->disabledWhitelist,
                (array) $this->json->unserialize($this->file->fileGetContents($file)),
            );
        }
        return $this->disabledWhitelist;
    }
}
