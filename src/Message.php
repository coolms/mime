<?php

declare(strict_types=1);

namespace CoolMS\Mime;

use CoolMS\Mime\Header\EncodedWordDecoder;
use CoolMS\Mime\Header\Headers;

use function array_keys;

/**
 * A parsed message: its root part, and the few conveniences that are genuinely about
 * MIME rather than about what an application wants to do with one.
 *
 * **What is deliberately NOT here.** Turning HTML into plain text, choosing which of
 * several bodies to display, deciding whether a message is a reply, stripping a
 * quoted history: those are presentation decisions that consume the part tree, and
 * each is a policy somebody will want to differ on. A parser that made them would
 * force its opinion on every consumer and would be the reason the next application
 * writes its own parser.
 *
 * What IS here is part SELECTION -- which parts are text, which are attachments --
 * because that is a fact about the MIME structure and every consumer would otherwise
 * re-derive the same walk.
 */
final readonly class Message
{
    public function __construct(
        public Part $root,
        private EncodedWordDecoder $words = new EncodedWordDecoder(),
    ) {
    }

    public function headers(): Headers
    {
        return $this->root->headers;
    }

    /** A top-level header with its RFC 2047 encoded-words decoded to UTF-8. */
    public function header(string $name, string $default = ''): string
    {
        $raw = $this->root->headers->value($name);

        return '' === $raw ? $default : $this->words->decode($raw);
    }

    public function subject(): string
    {
        return $this->header('subject');
    }

    /**
     * Every part in the tree, parents before children, depth first.
     *
     * @return list<Part>
     */
    public function allParts(): array
    {
        return $this->flatten($this->root);
    }

    /**
     * Every part that carries content rather than containing other parts.
     *
     * @return list<Part>
     */
    public function leaves(): array
    {
        $leaves = [];
        foreach ($this->allParts() as $part) {
            if ($part->isLeaf()) {
                $leaves[] = $part;
            }
        }

        return $leaves;
    }

    /**
     * The parts a person would call attachments, in the order they appear.
     *
     * @return list<Part>
     */
    public function attachments(): array
    {
        return $this->collectAttachments($this->root);
    }

    /**
     * The message's text bodies of one subtype, in order.
     *
     * @return list<Part>
     */
    public function bodyParts(string $subtype): array
    {
        $found = [];
        foreach ($this->allParts() as $part) {
            if ($part->isBodyText() && $part->contentType->is('text', $subtype)) {
                $found[] = $part;
            }
        }

        return $found;
    }

    /**
     * Whether anything in the tree was structurally wrong.
     *
     * @return list<string> defect slugs, with duplicates removed
     */
    public function defects(): array
    {
        $names = [];
        foreach ($this->allParts() as $part) {
            foreach ($part->defects->names() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * An embedded message IS an attachment, and the parts inside it are ITS
     * attachments rather than this message's -- so it is taken whole and the walk
     * stops there. Descending would report a forwarded message's three images as
     * though somebody had attached them to this one.
     *
     * @return list<Part>
     */
    private function collectAttachments(Part $part): array
    {
        if ($part->isEmbeddedMessage()) {
            return [$part];
        }

        $found = $part->isAttachment() ? [$part] : [];
        foreach ($part->parts() as $child) {
            foreach ($this->collectAttachments($child) as $descendant) {
                $found[] = $descendant;
            }
        }

        return $found;
    }

    /**
     * @return list<Part>
     */
    private function flatten(Part $part): array
    {
        $flat = [$part];
        foreach ($part->parts() as $child) {
            foreach ($this->flatten($child) as $descendant) {
                $flat[] = $descendant;
            }
        }

        return $flat;
    }
}
