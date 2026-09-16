<?php

declare(strict_types=1);

namespace CoolMS\Mime\Exception;

use RuntimeException;

use function sprintf;

/**
 * The bytes could not be obtained at all: a closed resource, a stream that cannot be
 * read, a temporary stream that could not be opened.
 *
 * Not for malformed MIME. A message that breaks every rule in RFC 2045 still parses
 * here, into whatever the rules say it is; this is for the case where there is
 * nothing to parse.
 */
final class UnreadableSourceException extends RuntimeException implements MimeException
{
    public static function notAStream(string $given): self
    {
        return new self(sprintf('A MIME source must be a string or a readable stream, %s given.', $given));
    }

    public static function notReadable(): self
    {
        return new self('The given stream is not readable.');
    }

    public static function temporaryStreamUnavailable(): self
    {
        return new self('Could not open a temporary stream to buffer a non-seekable source.');
    }
}
