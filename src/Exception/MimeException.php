<?php

declare(strict_types=1);

namespace CoolMS\Mime\Exception;

use Throwable;

/**
 * Every exception this library throws implements this, so a consumer can catch the
 * whole library with one `catch` and without naming a class hierarchy.
 *
 * There are deliberately FEW of these. A MIME parser meets malformed input as a
 * matter of course -- a missing boundary terminator, a header with no colon, a
 * declared charset that does not exist -- and treating each as an exception would
 * make the common case of real-world mail an error path. Those are tolerated and
 * reported as facts about the parse; an exception here means the SOURCE could not be
 * read at all, which is a different kind of problem and one the caller can act on.
 */
interface MimeException extends Throwable
{
}
