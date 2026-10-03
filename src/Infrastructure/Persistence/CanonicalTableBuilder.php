<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;

/** Builds canonical DBAL tables with the DBAL 4.5 editor API. */
final readonly class CanonicalTableBuilder
{
    private TableEditor $editor;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        $this->editor = Table::editor()->setUnquotedName($name);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $type
     * @param array<string, mixed> $options
     */
    public function addColumn(string $name, string $type, array $options = []): self
    {
        $column = Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setNotNull($this->boolOption($options, 'notnull', true));

        foreach (['length', 'precision'] as $option) {
            $value = $options[$option] ?? null;
            if ($value !== null) {
                if (!is_int($value)) {
                    throw new \InvalidArgumentException(sprintf('Column option "%s" must be an integer.', $option));
                }
                if ($option === 'length') {
                    $column->setLength($value);
                } else {
                    $column->setPrecision($value);
                }
            }
        }

        $scale = $options['scale'] ?? null;
        if ($scale !== null) {
            if (!is_int($scale)) {
                throw new \InvalidArgumentException('Column option "scale" must be an integer.');
            }
            $column->setScale($scale);
        }

        foreach (['unsigned', 'fixed'] as $option) {
            if (array_key_exists($option, $options)) {
                $enabled = $this->boolOption($options, $option, false);
                if ($option === 'unsigned') {
                    $column->setUnsigned($enabled);
                } else {
                    $column->setFixed($enabled);
                }
            }
        }

        if (array_key_exists('default', $options)) {
            $column->setDefaultValue($options['default']);
        }
        if (array_key_exists('autoincrement', $options)) {
            $column->setAutoincrement($this->boolOption($options, 'autoincrement', false));
        }

        $this->editor->addColumn($column->create());

        return $this;
    }

    /** @param non-empty-list<non-empty-string> $columns */
    public function setPrimaryKey(array $columns, ?string $name = null): self
    {
        $primaryKey = PrimaryKeyConstraint::editor()
            ->setUnquotedColumnNames(...$columns);
        if ($name !== null) {
            if ($name === '') {
                throw new \InvalidArgumentException('Primary key constraint names must not be empty.');
            }
            $primaryKey->setUnquotedName($name);
        }

        $this->editor->setPrimaryKeyConstraint($primaryKey->create());

        return $this;
    }

    /**
     * @param non-empty-list<non-empty-string> $columns
     * @param non-empty-string $name
     */
    public function addUniqueIndex(array $columns, string $name): self
    {
        $index = Index::editor()
            ->setUnquotedName($name)
            ->setType(IndexType::UNIQUE)
            ->setUnquotedColumnNames(...$columns)
            ->create();

        $this->editor->addIndex($index);

        return $this;
    }

    /**
     * @param non-empty-list<non-empty-string> $columns
     * @param non-empty-string $name
     */
    public function addIndex(array $columns, string $name): self
    {
        $index = Index::editor()
            ->setUnquotedName($name)
            ->setUnquotedColumnNames(...$columns)
            ->create();

        $this->editor->addIndex($index);

        return $this;
    }

    /**
     * @param non-empty-string $foreignTable
     * @param non-empty-list<non-empty-string> $localColumns
     * @param non-empty-list<non-empty-string> $foreignColumns
     * @param array<string, mixed> $options
     * @param ?non-empty-string $name
     */
    public function addForeignKeyConstraint(
        string $foreignTable,
        array $localColumns,
        array $foreignColumns,
        array $options = [],
        ?string $name = null,
    ): self {
        $foreignKey = ForeignKeyConstraint::editor()
            ->setUnquotedReferencingColumnNames(...$localColumns)
            ->setUnquotedReferencedTableName($foreignTable)
            ->setUnquotedReferencedColumnNames(...$foreignColumns);

        if ($name !== null) {
            $foreignKey->setUnquotedName($name);
        }
        foreach (['onDelete' => 'setOnDeleteAction', 'onUpdate' => 'setOnUpdateAction'] as $option => $setter) {
            if (isset($options[$option])) {
                if (!is_string($options[$option])) {
                    throw new \InvalidArgumentException(sprintf('Foreign key option "%s" must be a string.', $option));
                }
                $action = strtoupper(str_replace('_', ' ', $options[$option]));
                $foreignKey->{$setter}(ForeignKeyConstraint\ReferentialAction::from($action));
            }
        }

        $this->editor->addForeignKeyConstraint($foreignKey->create());

        return $this;
    }

    public function create(): Table
    {
        return $this->editor->create();
    }

    /** @param array<string, mixed> $options */
    private function boolOption(array $options, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        if (!is_bool($options[$name])) {
            throw new \InvalidArgumentException(sprintf('Column option "%s" must be a boolean.', $name));
        }

        return $options[$name];
    }
}
