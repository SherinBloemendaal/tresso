<?php

declare(strict_types=1);

namespace App\Enum;

/** iTIP METHOD of a calendar object sent by mail (RFC 5546). */
enum ItipMethod: string
{
    case Publish = 'PUBLISH';
    case Request = 'REQUEST';
    case Reply = 'REPLY';
    case Add = 'ADD';
    case Cancel = 'CANCEL';
    case Refresh = 'REFRESH';
    case Counter = 'COUNTER';
    case DeclineCounter = 'DECLINECOUNTER';
}
