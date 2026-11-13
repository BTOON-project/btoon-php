# Changelog

## 0.2.0 (2026-11-14)

Implements BTOON spec 0.2 (`BTOON\SPEC_VERSION` is now `'0.2'`; decisions D23–D26 in the
workspace's docs/DECISIONS.md). The wire format is unchanged: every valid 0.1 document that
fits the new limits decodes to the same value. The new limits, and the new `maps` default,
are why this is planned as 0.2.0.

- **Changed: `maps` defaults to `'auto'`** (was `'array'`). The maps that PHP would turn
  into lists, the empty map and maps whose keys are exactly `"0"`…`"n-1"` in that order,
  including the rows of a table with such columns, now decode to `ArrayObject` by
  default, so default round trips are lossless: `{}` (`80`) decodes to an empty
  `ArrayObject` and re-encodes as `80`, not `90`. Every other map is still an associative
  array. `'maps' => 'array'` keeps the 0.1.x mapping.
- **Added: the `max_values` decode option** (`DecodeOptions::$maxValues`), which bounds how
  many values a document decodes to (SPEC §7.1). Each value counts once when it starts,
  before anything inside it: the top-level value, array elements, map values and
  extensions of every type. A table counts when it starts, its rows count together once
  its header is checked, and each cell counts when it starts. Map keys and envelopes don't
  count; the values inside a compression envelope do. A document over the limit raises
  `size_limit`. SPEC's default is 2^20 (`DecodeOptions::DEFAULT_MAX_VALUES`); see below
  for PHP's.
- **Added: the tabular column-name bound.** A table whose rows × the total byte length of
  its column names exceed `max_decompressed_size` raises `size_limit` (SPEC §5.6.3). After
  the column names and type codes, a table's header is checked in this order: rows ×
  columns against the remaining payload (`invalid_tabular`), then the name bytes, then the
  row count against `max_values`.
- **Changed: `max_decompressed_size` defaults to 64 MiB** (`DEFAULT_MAX_DECOMPRESSED_SIZE`),
  down from 256 MiB.
- **Changed: the size defaults follow `memory_limit`,** as SPEC §7.1 allows for runtimes
  with a lower memory ceiling. When `max_values` or `max_decompressed_size` isn't given
  (or is null), its default is lowered to fit `memory_limit`: `max_values` to
  min(2^20, `memory_limit / 1024`) and `max_decompressed_size` to
  min(64 MiB, `memory_limit / 8`). PHP spends up to about 400 bytes per decoded value, so
  at SPEC's defaults a document could need about 0.5 GB. Under the usual
  `memory_limit=128M` the defaults are 131,072 values and 16 MiB; SPEC's apply from 1G,
  or with `-1`. Explicit values are used as given, and the `maxValues` and
  `maxDecompressedSize` properties hold the values in effect. The constructor parameters
  are now nullable, and `maxValues` is the last one.
- **Changed: `max_depth` can't exceed 1000** (`DecodeOptions::MAX_DEPTH_LIMIT`,
  `EncodeOptions::MAX_DEPTH_LIMIT`), when encoding or decoding; more raises
  `invalid_argument`. There was no upper bound.
- **Changed: non-strict mode checks map keys and column names.** `strict => false` now
  skips UTF-8 validation of string values only; an invalid map key or tabular column name
  raises `invalid_utf8` in both modes (SPEC §7), because keys compare by their bytes.
  Column names are checked front to back, each for UTF-8 and then against the earlier
  names, so the first bad name sets the error (SPEC §5.6.3); this was already the order in
  strict mode.
- **Fixed (denial of service):** under `memory_limit=128M`, zlib envelopes of 8 to 18 KB
  holding 2 million timestamps, 15 million empty maps or 8 million table rows ended in an
  uncatchable "Allowed memory size exhausted" fatal error. They now raise `size_limit`,
  at 65 MB peak or less, and a 61 KB envelope of a 60 MiB binary, which decoded at 128 MB
  peak, is refused before decompressing.
- **Unchanged, now covered by the vectors:** `BTOON\Ext` values with the ids BTOON defines
  (-1…-6 and -10) raise `invalid_argument`, and every other id, including those reserved
  for future versions (-9…-7, -128…-11), is written as given (SPEC §5.7). zlib payloads
  with FDICT set are `invalid_envelope` whatever their DICTID. An empty `hmac_key` raises
  `invalid_argument`.
- **Tests:** the vendored conformance vectors are spec 0.2's (298). The harness honors
  every vector option (`strict`, `max_depth`, `max_decompressed_size`, `max_values` and
  `hmac_key`, where an empty string is an empty key), decodes with the file's defaults
  given explicitly, runs the `encode_error` vectors, reads float vector elements bit for
  bit (`"NaN"` is the canonical quiet NaN of the width, `"0x…"` gives exact bits), and
  checks that what the default options decode re-encodes to the canonical bytes.

## 0.1.1 (2026-11-13)

- **Added:** the `maps` decode option (`DecodeOptions::$maps`, enum `BTOON\MapMode`). The
  maps that PHP turns into lists, the empty map and maps whose keys are exactly `"0"`,
  `"1"`, …, `"n-1"` in that order, decode to arrays, so they re-encode as BTOON arrays:
  `{}` (`80`) comes back as `[]` and re-encodes as `90`, and `{"0": 1}` (`81a13001`) as
  `[1]` and `9101`. With `'maps' => 'auto'`, those maps, including the rows of a table
  with such columns, decode to `ArrayObject`, which encodes as a map, while every other
  map stays an associative array, so maps survive a round trip. `'object'` returns every
  map as an `ArrayObject`. The default, `'array'`, keeps the 0.1.0 behavior. `'auto'` is
  recommended, and becomes the default in 0.2.0.
- **Fixed (denial of service):** a table no longer creates the rows its header announces
  before decoding them. Each nesting level was checked against the same remaining bytes,
  so a 1 MiB document of 64 nested tables (1.6 KB as a zlib envelope) took 2.4 GB, or a
  fatal error under any usual `memory_limit`. Rows are now created as their first cell is
  decoded, so the same document needs only what its innermost valid table does (2 MB when
  that table is small).

## 0.1.0 (2026-11-07)

First release: a pure PHP implementation of BTOON spec 0.1.
