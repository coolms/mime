# Changelog

All notable changes to `coolms/mime` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
There is no tagged release yet, so everything the package does today is listed
under Unreleased.

## Unreleased

### Added
- `MessageParser::parse()`, which reads a message from a string or a stream
  resource into a tree of parts (`Message`, `Part`), reading each level once and
  copying nothing.
- Headers per RFC 5322: folding, duplicate fields kept in order, malformed lines
  counted as defects; RFC 2047 encoded words in headers and, tolerated, in
  parameters; RFC 6532 UTF-8 headers passed through intact.
- Content-Type and Content-Disposition with RFC 2231 parameter continuations,
  out-of-order segments and charset/language values; the filename falls back to
  the Content-Type name parameter.
- Multipart bodies per RFC 2046: nested multiparts, preambles and epilogues,
  digests, and an unterminated multipart read to its end and recorded as a defect.
  `message/rfc822` and `message/global` parts are parsed as whole messages.
- base64 and quoted-printable decoding as stream filters, so a large part is never
  held whole; a part's encoded size, and its decoded size where it is exact.
- Charset conversion to UTF-8 without `ext-mbstring` or `ext-iconv`, reporting
  the charset used and whether it was a fallback.
- Non-seekable sources (a socket, a pipe): read once, then parsed as a seekable
  copy.
- CONFORMANCE.md, generated from the test run: each RFC row, whether it is
  implemented, and what it was verified against (unit tests, a shared corpus, a
  differential comparison with another parser).
- README, LICENSE (MIT) and this changelog.
