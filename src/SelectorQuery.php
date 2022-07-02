<?php

/**
 * Part of Windwalker Packages project.
 *
 * @copyright  Copyright (C) 2021 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\ORM;

use InvalidArgumentException;
use Windwalker\Data\Collection;
use Windwalker\Database\DatabaseAdapter;
use Windwalker\Database\Event\HydrateEvent;
use Windwalker\Database\Event\ItemFetchedEvent;
use Windwalker\Event\EventAwareInterface;
use Windwalker\Event\EventAwareTrait;
use Windwalker\ORM\Metadata\EntityMetadata;
use Windwalker\ORM\Relation\Strategy\ManyToMany;
use Windwalker\Query\Clause\AsClause;
use Windwalker\Query\Query;
use Windwalker\Utilities\Arr;

use function Windwalker\Query\val;

use const Windwalker\Query\QN_IGNORE_DOTS;

/**
 * The SelectAction class.
 *
 * @property-read DatabaseAdapter $db
 * @property-read ORM             $orm
 *
 * Query methods.
 */
class SelectorQuery extends Query implements EventAwareInterface
{
    use EventAwareTrait;

    protected ?string $groupDivider = null;

    /**
     * @inheritDoc
     */
    public function __construct(ORM $orm, $grammar = null)
    {
        parent::__construct($orm, $grammar ?? $orm->getDb()->getPlatform()->getGrammar());

        $this->bindEvents();
    }

    protected function bindEvents(): void
    {
        $this->on(
            ItemFetchedEvent::class,
            function (ItemFetchedEvent $event) {
                if ($this->groupDivider !== null) {
                    $item = $this->groupItem($event->getItem());
                    $event->setItem($item);
                }
            }
        );

        $this->on(
            HydrateEvent::class,
            function (HydrateEvent $event) {
                $orm = $this->getORM();
                $item = $event->getItem();

                if ($item === null) {
                    return;
                }

                $object = $event->getClass();

                if (is_string($object)) {
                    $object = $orm->getAttributesResolver()->createObject($object);
                }

                $object = $orm->hydrateEntity($item, $object);

                if (EntityMetadata::isEntity($object)) {
                    // Prepare relations
                    $orm->getEntityMetadata($object)
                        ->getRelationManager()
                        ->load($item, $object);
                }

                $event->setItem($object);
            }
        );
    }

    public function autoSelections(string $divider = '.', array &$columns = null): static
    {
        $columns ??= [];

        /** @var array<int, AsClause> $tables */
        $tables = array_values(Arr::collapse($this->getAllTables()));

        $db = $this->getDb();

        foreach ($tables as $i => $clause) {
            if ($clause->getValue() instanceof Query) {
                continue;
            }

            $tbm = $db->getTable(
                static::convertClassToTable($clause->getValue(), $alias)
            );

            $cols = $tbm->getColumnNames();

            foreach ($cols as $col) {
                $alias = $clause->getAlias() ?? $alias;

                if ($i === 0) {
                    $as = $col;
                } else {
                    $as = $alias . $divider . $col;
                }

                $columns[] = $alias . $divider . $col;

                $this->selectRaw(
                    '%n AS %r',
                    $alias . '.' . $col,
                    $this->quoteName($as, QN_IGNORE_DOTS)
                );
            }
        }

        return $this;
    }

    public function groupByDivider(?string $divider = '.'): static
    {
        $this->groupDivider = $divider;

        return $this;
    }

    public function groupByJoins(string $divider = '.'): static
    {
        return $this->autoSelections($divider)
            ->groupByDivider($divider);
    }

    protected function groupItem(?array $item): ?array
    {
        if ($item === null) {
            return null;
        }

        foreach ($item as $k => $value) {
            if (str_contains($k, $this->groupDivider)) {
                [$prefix, $key] = explode($this->groupDivider, $k, 2);

                $item[$prefix] ??= new Collection();

                $item[$prefix][$key] = $value;

                unset($item[$k]);
            }
        }

        return $item;
    }

    public function join(string $type, mixed $table, ?string $alias = null, ...$on): static
    {
        if (!$on && is_string($table) && class_exists($table)) {
            $on = $this->handleAutoJoin($type, $table, $alias, $on);
        }

        return parent::join($type, $table, $alias, ...$on);
    }

    private function handleAutoJoin(string $type, string $table, ?string &$alias, array $on): array
    {
        /** @var AsClause|null $fromClause */
        $fromClause = $this->getFrom()?->getElements()[0] ?? null;
        $from = $fromClause?->getValue();

        if (!$from) {
            return $on;
        }

        $fromMetadata = $this->getORM()->getEntityMetadata($from);
        $joinMetadata = $this->getORM()->getEntityMetadata($table);
        $relation = null;

        $fromAlias = $fromMetadata->getTableAlias();
        $alias ??= $joinMetadata->getTableAlias();

        foreach ($fromMetadata->getRelationManager()->getRelations() as $relation) {
            if ($relation instanceof ManyToMany) {
                $mapMetadata = $relation->getMapMetadata();
                $mapAlias = $mapMetadata->getTableAlias();

                if ($relation->getMapTable() === $table) {
                    foreach ($relation->getMapForeignKeys() as $ok => $mfk) {
                        $on[] = ["$fromAlias.$ok", '=', "$alias.$mfk"];
                    }

                    foreach ($relation->getMap()->getMorphs() as $field => $value) {
                        $on[] = ["$mapAlias.$field", '=', val($value)];
                    }

                    return [$on];
                }

                if ($relation->getTargetTable() === $table) {
                    foreach ($relation->getForeignKeys() as $mk => $fk) {
                        $on[] = ["$mapAlias.$mk", '=', "$alias.$fk"];
                    }

                    foreach ($relation->getTarget()->getMorphs() as $field => $value) {
                        $on[] = ["$alias.$field", '=', val($value)];
                    }

                    return [$on];
                }
            }

            if ($relation->getTargetTable() === $table) {
                foreach ($relation->getForeignKeys() as $ok => $fk) {
                    $on[] = ["$fromAlias.$ok", '=', "$alias.$fk"];
                }

                foreach ($relation->getMorphs() as $field => $value) {
                    $on[] = ["$alias.$field", '=', val($value)];
                }

                return [$on];
            }
        }

        return $on;
    }

    public function getDb(): DatabaseAdapter
    {
        return $this->getORM()->getDb();
    }

    public function getORM(): ORM
    {
        return $this->getEscaper()->getConnection();
    }

    public function __get(string $name)
    {
        if ($name === 'db') {
            return $this->getDb();
        }

        if ($name === 'orm') {
            return $this->getORM();
        }

        throw new InvalidArgumentException(
            sprintf(
                'Property is %s undefined in %s',
                $name,
                static::class
            )
        );
    }

    /**
     * When an object is cloned, PHP 5 will perform a shallow copy of all of the object's properties.
     * Any properties that are references to other variables, will remain references.
     * Once the cloning is complete, if a __clone() method is defined,
     * then the newly created object's __clone() method will be called, to allow any necessary properties that need to
     * be changed. NOT CALLABLE DIRECTLY.
     *
     * @return void
     * @throws \ReflectionException
     * @link https://php.net/manual/en/language.oop5.cloning.php
     */
    public function __clone(): void
    {
        parent::__clone();

        $this->dispatcher = clone $this->dispatcher;

        $this->dispatcher->off(ItemFetchedEvent::class);
        $this->dispatcher->off(HydrateEvent::class);

        $this->bindEvents();
    }

    /**
     * createSubQuery
     *
     * @return  static
     */
    public function createSubQuery(): static
    {
        return new static($this->orm, $this->grammar);
    }
}
