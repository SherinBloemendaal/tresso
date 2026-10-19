<?php

declare(strict_types=1);

namespace App\Enum;

/** What the worker does with one calendar resource at the provider. */
enum CalendarOperationKind: string
{
    case Put = 'put';
    case Delete = 'delete';
}
