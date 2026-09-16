<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Corpus;

use function base64_encode;
use function chunk_split;
use function implode;
use function str_repeat;

/**
 * The corpus, built in code rather than stored as files.
 *
 * **Line endings are DATA here, and a file cannot be trusted to keep them.** Several
 * of these messages exist precisely to exercise a bare LF, a missing final
 * terminator, or a CRLF that belongs to a delimiter rather than a body. A `.eml` file
 * carrying those passes through an editor, a `git` checkout with autocrlf, and a
 * reviewer's clipboard, any of which will quietly normalise the thing under test.
 * Assembling each message from an array with an explicit terminator makes the bytes
 * exactly what they say they are.
 *
 * **Nothing here is real mail.** Every message is synthetic: invented addresses at
 * `.test` and `.invalid`, invented names, invented bodies. That is a rule and not a
 * convenience -- a corpus is committed, published with the package, and read by
 * strangers, and a scrubbed real message is one careless line away from not being
 * scrubbed. Where a shape genuinely needs a real-world quirk, the quirk is
 * reproduced and the content around it is invented.
 */
final readonly class Corpus
{
    /**
     * @return list<CorpusMessage>
     */
    public static function all(): array
    {
        return [
            ...self::wellFormed(),
            ...self::headerConstructs(),
            ...self::structuralDefects(),
            ...self::encodingDefects(),
        ];
    }

    /**
     * @return list<CorpusMessage>
     */
    private static function wellFormed(): array
    {
        return [
            new CorpusMessage('plain-text', ['no-mime-headers'], self::crlf([
                'From: sender@example.test',
                'To: recipient@example.test',
                'Subject: a message with no MIME headers at all',
                '',
                'Just a body.',
                '',
            ])),

            new CorpusMessage('alternative', ['multipart-alternative'], self::crlf([
                'Subject: two bodies',
                'Content-Type: multipart/alternative; boundary="alt"',
                '',
                '--alt',
                'Content-Type: text/plain; charset=utf-8',
                '',
                'the plain one',
                '--alt',
                'Content-Type: text/html; charset=utf-8',
                '',
                '<p>the html one</p>',
                '--alt--',
                '',
            ])),

            new CorpusMessage('nested-three-deep', ['nesting-depth'], self::crlf([
                'Subject: mixed containing related containing alternative',
                'Content-Type: multipart/mixed; boundary="l1"',
                '',
                '--l1',
                'Content-Type: multipart/related; boundary="l2"',
                '',
                '--l2',
                'Content-Type: multipart/alternative; boundary="l3"',
                '',
                '--l3',
                'Content-Type: text/plain',
                '',
                'depth three plain',
                '--l3',
                'Content-Type: text/html',
                '',
                '<p>depth three html</p>',
                '--l3--',
                '--l2',
                'Content-Type: image/png',
                'Content-Transfer-Encoding: base64',
                'Content-ID: <logo@example.test>',
                'Content-Disposition: inline; filename="logo.png"',
                '',
                base64_encode('not really a png'),
                '--l2--',
                '--l1',
                'Content-Type: application/pdf',
                'Content-Transfer-Encoding: base64',
                'Content-Disposition: attachment; filename="report.pdf"',
                '',
                base64_encode('not really a pdf'),
                '--l1--',
                '',
            ])),

            new CorpusMessage('attached-message', ['attached-message'], self::crlf([
                'Subject: forwarding one',
                'Content-Type: multipart/mixed; boundary="fwd"',
                '',
                '--fwd',
                'Content-Type: text/plain',
                '',
                'see below',
                '--fwd',
                'Content-Type: message/rfc822',
                'Content-Disposition: attachment; filename="forwarded.eml"',
                '',
                'From: original@example.test',
                'Subject: the original',
                'Content-Type: text/plain',
                '',
                'the original body',
                '--fwd--',
                '',
            ])),

            new CorpusMessage('message-global', ['attached-message', 'rfc6532-utf8-headers'], self::crlf([
                'Subject: an internationalised forward',
                'Content-Type: multipart/mixed; boundary="g"',
                '',
                '--g',
                'Content-Type: message/global',
                '',
                'From: Grüße <grusse@example.test>',
                'Subject: Grüße aus München',
                'Content-Type: text/plain; charset=utf-8',
                '',
                'UTF-8 in the headers, not an encoded-word.',
                '--g--',
                '',
            ])),

            new CorpusMessage('base64-attachment', ['base64-attachment'], self::crlf([
                'Subject: with a file',
                'Content-Type: multipart/mixed; boundary="att"',
                '',
                '--att',
                'Content-Type: text/plain',
                '',
                'here it is',
                '--att',
                'Content-Type: application/octet-stream',
                'Content-Transfer-Encoding: base64',
                'Content-Disposition: attachment; filename="payload.bin"',
                '',
                chunk_split(base64_encode(str_repeat('0123456789abcdef', 64)), 76, "\r\n") . '--att--',
                '',
            ])),

            new CorpusMessage('quoted-printable', ['quoted-printable-body'], self::crlf([
                'Subject: soft line breaks',
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: quoted-printable',
                '',
                'Gr=C3=BC=C3=9Fe und ein sehr langer Satz der umgebrochen wird weil er l=',
                'aenger ist als die Zeile erlaubt.',
                '',
            ])),

            new CorpusMessage('digest', ['multipart-digest'], self::crlf([
                'Subject: a digest',
                'Content-Type: multipart/digest; boundary="d"',
                '',
                '--d',
                '',
                'Subject: first enclosed',
                '',
                'first body',
                '--d',
                '',
                'Subject: second enclosed',
                '',
                'second body',
                '--d--',
                '',
            ])),
        ];
    }

    /**
     * @return list<CorpusMessage>
     */
    private static function headerConstructs(): array
    {
        return [
            new CorpusMessage('encoded-word-subject', ['encoded-word-adjacent'], self::crlf([
                'Subject: =?utf-8?Q?Gr=C3=BC?= =?utf-8?Q?=C3=9Fe?= from =?iso-8859-1?Q?M=FCnchen?=',
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ])),

            new CorpusMessage('encoded-word-folded', ['encoded-word-adjacent', 'folded-header'], self::crlf([
                'Subject: =?utf-8?Q?Gr=C3=BC?=',
                ' =?utf-8?Q?=C3=9Fe?=',
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ])),

            new CorpusMessage('encoded-word-broken', ['encoded-word-broken'], self::crlf([
                'Subject: =?utf-8?X?not-an-encoding?= and =?utf-8?B?!!!?=',
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ])),

            new CorpusMessage('encoded-word-filename', ['encoded-word-filename'], self::crlf([
                'Content-Type: multipart/mixed; boundary="e"',
                '',
                '--e',
                'Content-Type: application/octet-stream',
                'Content-Disposition: attachment; filename="=?utf-8?B?w7xiZXIudHh0?="',
                '',
                'x',
                '--e--',
                '',
            ])),

            new CorpusMessage('rfc2231-continuation', ['rfc2231-continuation'], self::crlf([
                'Content-Type: multipart/mixed; boundary="c"',
                '',
                '--c',
                'Content-Type: application/octet-stream',
                'Content-Disposition: attachment; filename*0="a rather long "; filename*1="file name.txt"',
                '',
                'x',
                '--c--',
                '',
            ])),

            new CorpusMessage('rfc2231-out-of-order', ['rfc2231-continuation', 'rfc2231-out-of-order'], self::crlf([
                'Content-Type: multipart/mixed; boundary="c"',
                '',
                '--c',
                'Content-Type: application/octet-stream',
                'Content-Disposition: attachment; filename*1="second.txt"; filename*0="first-"',
                '',
                'x',
                '--c--',
                '',
            ])),

            new CorpusMessage('rfc2231-extended', ['rfc2231-extended-charset'], self::crlf([
                'Content-Type: multipart/mixed; boundary="c"',
                '',
                '--c',
                'Content-Type: application/octet-stream',
                "Content-Disposition: attachment; filename*=iso-8859-1'de'%FCber.txt",
                '',
                'x',
                '--c--',
                '',
            ])),

            new CorpusMessage('rfc2231-mixed', ['rfc2231-continuation', 'rfc2231-extended-charset'], self::crlf([
                'Content-Type: multipart/mixed; boundary="c"',
                '',
                '--c',
                'Content-Type: application/octet-stream',
                "Content-Disposition: attachment; filename*0*=utf-8''%C3%BC; filename*1*=ber-teil-zwei.txt",
                '',
                'x',
                '--c--',
                '',
            ])),

            new CorpusMessage('boundary-with-semicolon', ['quoted-parameter-separator'], self::crlf([
                'Content-Type: multipart/mixed; boundary="a;b"',
                '',
                '--a;b',
                'Content-Type: text/plain',
                '',
                'the separator is inside the quotes',
                '--a;b--',
                '',
            ])),

            new CorpusMessage('duplicate-headers', ['duplicate-headers'], self::crlf([
                'Received: from one.example.test',
                'Received: from two.example.test',
                'Subject: first subject',
                'Subject: second subject',
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ])),

            new CorpusMessage('eight-bit-headers', ['eight-bit-headers'], self::crlf([
                "Subject: raw bytes \xC3\xA9 and \xFF\xFE here",
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ])),
        ];
    }

    /**
     * @return list<CorpusMessage>
     */
    private static function structuralDefects(): array
    {
        return [
            new CorpusMessage('unterminated-multipart', ['unterminated-boundary'], self::crlf([
                'Content-Type: multipart/mixed; boundary="u"',
                '',
                '--u',
                'Content-Type: text/plain',
                '',
                'this one never closes',
            ]), ['unterminated-multipart']),

            new CorpusMessage('missing-boundary', ['missing-boundary'], self::crlf([
                'Content-Type: multipart/mixed',
                '',
                'a multipart that declared no boundary at all',
                '',
            ]), ['multipart-without-boundary']),

            new CorpusMessage('boundary-never-appears', ['missing-boundary'], self::crlf([
                'Content-Type: multipart/mixed; boundary="absent"',
                '',
                'the delimiter is nowhere in this body',
                '',
            ]), ['no-parts-found']),

            new CorpusMessage('boundary-inside-body', ['boundary-in-body'], self::crlf([
                'Content-Type: multipart/mixed; boundary="edge"',
                '',
                '--edge',
                'Content-Type: text/plain',
                '',
                'a line that looks close:',
                '--edgeXXX',
                'and one that is a prefix:',
                '--edg',
                '--edge--',
                '',
            ])),

            new CorpusMessage('nested-reused-boundary', ['nested-reused-boundary'], self::crlf([
                'Content-Type: multipart/mixed; boundary="same"',
                '',
                '--same',
                'Content-Type: multipart/alternative; boundary="same"',
                '',
                '--same',
                'Content-Type: text/plain',
                '',
                'ambiguous by construction',
                '--same--',
                '',
                // Measured, not assumed: the parent claims both delimiters, so the
                // inner multipart is handed a range containing no delimiter of its
                // own and reports that it found no parts. That is the honest outcome
                // for a message whose boundaries cannot be told apart, and the
                // alternative -- inventing a nesting the bytes do not determine --
                // would be a guess wearing a tree.
            ]), ['no-parts-found']),

            new CorpusMessage('epilogue-with-boundary', ['epilogue-with-boundary'], self::crlf([
                'Content-Type: multipart/mixed; boundary="ep"',
                '',
                '--ep',
                'Content-Type: text/plain',
                '',
                'the only part',
                '--ep--',
                'epilogue text that mentions --ep again',
                '--ep',
                'and pretends to open another part',
                '',
            ])),

            new CorpusMessage('header-with-no-colon', ['header-no-colon'], self::crlf([
                'Subject: fine',
                'this line has no colon at all',
                'Content-Type: text/plain',
                '',
                'body',
                '',
            ]), ['malformed-header-lines']),

            new CorpusMessage('part-with-no-blank-line', ['part-without-body'], self::crlf([
                'Content-Type: multipart/mixed; boundary="nb"',
                '',
                '--nb',
                'Content-Type: text/plain',
                '--nb',
                'Content-Type: text/html',
                '',
                '<p>the sibling survived</p>',
                '--nb--',
                '',
            ])),

            new CorpusMessage('headers-only', ['headers-only'], self::crlf([
                'Subject: no body follows at all',
                'Content-Type: text/plain',
            ])),
        ];
    }

    /**
     * @return list<CorpusMessage>
     */
    private static function encodingDefects(): array
    {
        return [
            // A whole message terminated with bare LF, which is what an mbox and any
            // naive generator produce.
            new CorpusMessage('bare-lf-endings', ['bare-lf'], self::lf([
                'Subject: unix line endings throughout',
                'Content-Type: multipart/mixed; boundary="lf"',
                '',
                '--lf',
                'Content-Type: text/plain',
                '',
                'a body with bare LF',
                '--lf--',
                '',
            ])),

            new CorpusMessage('wrong-transfer-encoding', ['wrong-cte'], self::crlf([
                'Subject: says base64 and is not',
                'Content-Type: text/plain',
                'Content-Transfer-Encoding: base64',
                '',
                'this is plainly not base64 !!!',
                '',
            ])),

            new CorpusMessage('unknown-transfer-encoding', ['wrong-cte'], self::crlf([
                'Subject: an encoding nobody implements',
                'Content-Type: text/plain',
                'Content-Transfer-Encoding: x-something-invented',
                '',
                'the bytes as they are',
                '',
            ]), ['unknown-transfer-encoding']),

            new CorpusMessage('missing-transfer-encoding', ['missing-cte'], self::crlf([
                'Subject: eight-bit body, no declaration',
                'Content-Type: text/plain; charset=utf-8',
                '',
                'Grüße with no Content-Transfer-Encoding header.',
                '',
            ])),

            new CorpusMessage('wrong-charset', ['wrong-charset'], self::crlf([
                'Subject: declares a charset it is not',
                'Content-Type: text/plain; charset=us-ascii',
                '',
                'Grüße are not us-ascii.',
                '',
            ])),

            new CorpusMessage('nonexistent-charset', ['wrong-charset'], self::crlf([
                'Subject: declares a charset nobody has',
                'Content-Type: text/plain; charset=x-invented-charset-9000',
                '',
                'some bytes',
                '',
            ])),
        ];
    }

    /**
     * @param list<string> $lines
     */
    private static function crlf(array $lines): string
    {
        return implode("\r\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private static function lf(array $lines): string
    {
        return implode("\n", $lines);
    }
}
