<?php

declare(strict_types=1);

namespace ElementaryFramework\Core\Async;

/**
 * Enumeration of possible states for a Promise.
 *
 * A Promise can be in one of three states:
 * - PENDING: Initial state, neither fulfilled nor rejected
 * - FULFILLED: The operation completed successfully
 * - REJECTED: The operation failed with an error
 */
enum PromiseState: string
{
    case PENDING = 'pending';
    case FULFILLED = 'fulfilled';
    case REJECTED = 'rejected';

    /**
     * Check if the promise is in a settled state (fulfilled or rejected).
     *
     * @return bool True if the promise is settled, false if still pending
     */
    public function isSettled(): bool
    {
        return $this !== self::PENDING;
    }

    /**
     * Check if the promise is fulfilled.
     *
     * @return bool True if the promise is fulfilled
     */
    public function isFulfilled(): bool
    {
        return $this === self::FULFILLED;
    }

    /**
     * Check if the promise is rejected.
     *
     * @return bool True if the promise is rejected
     */
    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Check if the promise is pending.
     *
     * @return bool True if the promise is pending
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}
