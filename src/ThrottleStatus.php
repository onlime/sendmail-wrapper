<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Throttle status, used as exit status code of sendmail-throttle.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
enum ThrottleStatus: int
{
    /** Limit not reached */
    case Ok = 0;

    /** Limit reached, sending notification to admin */
    case QuotaReached = 1;

    /** Over limit, admin has already been notified */
    case OverQuota = 2;

    /** User is blocked */
    case Blocked = 3;

    /** Throttling failed, e.g. database not available */
    case Exception = 4;
}
