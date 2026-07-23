<?php

namespace App\Services\Automation;

/**
 * Loop-prevention flag (spec 02 §4.6). While the dispatcher is applying rule actions, this is true;
 * order observers and OrderController check it and skip re-dispatching automation, so a rule-caused
 * write never implicitly re-triggers the engine.
 */
class Automation
{
    public static bool $applying = false;

    public static function applying(): bool
    {
        return self::$applying;
    }

    /** Run $callback with the applying flag set, restoring it afterwards even on exception. */
    public static function whileApplying(callable $callback): mixed
    {
        $previous = self::$applying;
        self::$applying = true;
        try {
            return $callback();
        } finally {
            self::$applying = $previous;
        }
    }
}
