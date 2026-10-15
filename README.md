# coolms/mime

[![CI](https://github.com/coolms/mime/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/mime/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**A streaming MIME parser for PHP.** It reads an email message -- a string or a
stream -- into a tree of parts, and decodes each part only as its content is read,
so a message read from a file is never held whole in memory.

- **Standards:** RFC 5322 (message format), RFC 2045-2049 (MIME), RFC 2047
  (encoded words), RFC 2231 (parameter continuations and charsets), RFC 2183
  (Content-Disposition) and RFC 6532 (UTF-8 headers), including `message/rfc822`
  and `message/global` parts parsed as whole messages.
- **Streaming:** base64 and quoted-printable are decoded as stream filters, so a
  large attachment is never held whole; several part streams can be open over one
  message at once.
- **No dependencies:** plain PHP. No `ext-mailparse`, and neither `ext-mbstring`
  nor `ext-iconv` is required for charset conversion.
- **Malformed input is reported, not guessed at:** an unterminated multipart, an
  unknown transfer encoding or a malformed header line is parsed as far as the
  standard allows and recorded as a defect on the message.

What is covered, row by row, and what each row was verified against, is in
[CONFORMANCE.md](CONFORMANCE.md). The file is generated from the test run, and
the build refuses to write it while a row declared implemented has no passing
test. Three rows are deliberately not implemented: reassembling `message/partial`
fragments, fetching `message/external-body` content, and RFC 2048, which is a
registration procedure with nothing to parse.

## Requirements

PHP 8.5 or later.

## Installation

```bash
composer require coolms/mime
```

There is no tagged release yet. Until there is, require the development branch:

```bash
composer require coolms/mime:dev-main
```

## Usage

```php
use CoolMS\Mime\MessageParser;

$raw = "From: Ada Lovelace <ada@example.org>\r\n"
    . "Subject: =?UTF-8?Q?Caf=C3=A9_menu?=\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: multipart/mixed; boundary=\"b1\"\r\n"
    . "\r\n"
    . "--b1\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n"
    . "\r\n"
    . "See the attached menu.\r\n"
    . "--b1\r\n"
    . "Content-Type: text/plain; name=\"menu.txt\"\r\n"
    . "Content-Disposition: attachment; filename=\"menu.txt\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "\r\n"
    . "Q29mZmVlLCB0ZWEu\r\n"
    . "--b1--\r\n";

$message = (new MessageParser())->parse($raw);

echo $message->subject(), "\n";
echo $message->bodyParts('plain')[0]->text()->text, "\n";
foreach ($message->attachments() as $attachment) {
    echo $attachment->filename(), ': ', $attachment->content(), "\n";
}
```

Output:

```text
Café menu
See the attached menu.
menu.txt: Coffee, tea.
```

`parse()` also accepts a stream resource, for example `fopen('message.eml', 'rb')`.
A part's `stream()` gives its decoded content as a stream, for content too large
to read as a string; `text()` converts it to UTF-8 and reports the charset it used
and whether that was a fallback. `$message->defects()` lists what was structurally
wrong with the input.

The example above is run by the test suite (`tests/Unit/ReadmeExampleTest.php`),
which compares its output with the block under it.

## Contributing

See the CoolMS organization's
[contributing guide](https://github.com/coolms/.github/blob/develop/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
