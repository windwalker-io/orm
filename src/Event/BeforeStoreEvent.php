<?php

/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2023 LYRASOFT.
 * @license    MIT
 */

declare(strict_types=1);

namespace Windwalker\ORM\Event;

use Attribute;

/**
 * Before data really store to DB event.
 */
#[Attribute]
class BeforeStoreEvent extends AbstractSaveEvent
{
    //
}
