<?php

declare(strict_types=1);

namespace Windwalker\ORM\Attributes;

use Ramsey\Uuid\UuidInterface;
use Windwalker\Cache\Exception\LogicException;
use Windwalker\ORM\Cast\CastInterface;
use Windwalker\Query\Wrapper\UuidBinWrapper;
use Windwalker\Query\Wrapper\UuidWrapper;

/**
 * The UUID class.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class UUIDBin extends CastForSave implements CastInterface
{
    public const NULLABLE = 1 << 0;

    /**
     * CastForSave constructor.
     */
    public function __construct(
        public string $version = 'uuid7',
        public mixed $caster = null,
        public int $options = 0
    ) {
        $this->caster ??= $this->getUUIDCaster();
    }

    public function getUUIDCaster(): \Closure
    {
        return function ($value) {
            if (!class_exists(\Ramsey\Uuid\Uuid::class)) {
                throw new LogicException('Please install ramsey/uuid ^4.0 first.');
            }

            $method = $this->version;

            if (!$value && ($this->options & static::NULLABLE)) {
                return null;
            }

            return new UuidBinWrapper($value ?: \Ramsey\Uuid\Uuid::$method());
        };
    }

    public function hydrate(mixed $value): mixed
    {
        return UuidBinWrapper::tryWrap($value);
    }

    public function extract(mixed $value): mixed
    {
        return UuidBinWrapper::tryWrap($value);
    }

    public static function wrap(mixed $value): UuidInterface
    {
        return UuidBinWrapper::wrap($value);
    }

    public static function tryWrap(mixed $value): ?UuidInterface
    {
        return UuidBinWrapper::tryWrap($value);
    }
}
