# Changelog

## 0.3.0 (2026-11-14)

Implements BTOON spec 0.3 (`BTOON\SPEC_VERSION` is now `'0.3'`). The wire format and SPEC's
limits are unchanged. Spec 0.3 tightens what an LZ4 block may be (§6), which liblz4 doesn't
fully check, so LZ4 envelopes are now checked in PHP first. It also requires timestamp, date
and datetime values to be writable, with a decoding mode that keeps them exact, which
already held here. This release also fixes the remaining findings of the 2026-11-13 review.
The behavior changes are the new `max_map_entries` limit, the stricter LZ4 check and the
`BigInt::toInt()` error message.

- **Changed: a map may have at most `max_map_entries` entries, 4096 by default**
  (`DecodeOptions::$maxMapEntries`, `DEFAULT_MAX_MAP_ENTRIES`), and a table at most that
  many columns. More raises `size_limit`, once the announced count has been checked against
  the input, with a message that names the option. This is PHP's own limit, not SPEC's. PHP
  hashes array keys without a secret seed, so keys can be chosen to share one hash, and then
  each key added to a map is compared with every key before it: one map of 65,536 such keys
  took 9 s to decode, and one of 2^20 keys, within SPEC's value limit, would take over half
  an hour. With the limit, a document whose keys all collide decodes in 0.6 s under
  `memory_limit=128M` (131,072 values; ordinary keys take 0.03 s), and in 4.8 s at 2^20
  values, which apply from a `memory_limit` of 1G (ordinary keys: 0.24 s; 2^20 timestamps:
  0.44 s). The cap is a trade-off: 1,024 would bound that to 1.3 s, but would reject
  ordinary documents that every other BTOON library decodes, such as a few thousand entries
  keyed by id. To decode larger maps, such as the 65,536-entry map of the reference cases,
  raise it (`'max_map_entries' => 100_000`); for untrusted input with a high `memory_limit`,
  consider 1,024.
- **Fixed (denial of service):** tables no longer hash their column names once per cell. A
  table wider than 16 columns builds its rows as copies of a template row: 1,024 colliding
  names × 1,023 rows took 1.2 s, now 0.08 s, as long as ordinary names take (4,096 × 255:
  4.2 s, now 0.1 s). A table without rows, which counts as one value, compares keyed digests
  of its names: 61 MB of empty tables with 1,024 colliding names took 4.6 s, now 0.7 s.
- **Fixed (denial of service):** the decoder and the encoder each cache at most 64 map keys,
  down from 4,096. The caches are PHP arrays, so with colliding keys each lookup compared up
  to 4,096 keys: 524,287 one-entry maps took 7.0 s to decode, now 0.26 s, and re-encoding
  1,023 decoded maps of 1,024 colliding keys took 6.8 s, now 0.4 s.
- **Changed: `BigInt::toInt()` describes a value longer than 128 bits by its size** ("an
  integer of 799999 bits does not fit in a 64-bit PHP int") instead of spelling it out in
  decimal, which took 15.6 s for a 100 KB bigint without ext-gmp. `toInt()` and `fitsInt()`
  no longer convert a value given as a decimal string of more than 19 digits.
- **Fixed:** `Date::fromDateTime()` and `DateTime::fromDateTime()` accept every instant in
  their 64-bit range. They refused the earliest second of it, so the earliest date, decoded
  to a `DateTimeImmutable`, couldn't become a `Date` again.
- **Changed: LZ4 blocks are checked against SPEC §6 before ext-lz4 decodes them.** A block
  must be valid under the LZ4 block format, end-of-block conditions included: its last
  sequence holds only literals, its last 5 bytes of output are literals, and its last match
  starts at least 12 bytes before the end. No match offset may be 0 or reach back before the
  start of the output. Anything else is `invalid_envelope`. liblz4, which ext-lz4 uses,
  skips the end-of-block and offset-0 checks on its fast path, so 0.2.0 accepted the vectors
  lz4-block/long-output-ends-with-match and lz4-block/offset-zero. The check is one pass over
  the block's sequences in PHP, without copying: 0.2 s for the 4 million sequences that
  16 MiB of output can hold. A block that is a single token without literals now decodes to
  an empty document whatever its match bits, as SPEC §6 says they are ignored; only `00` did.
- **Unchanged, now covered by the vectors:** `Timestamp`, `Date` and `DateTime` write the
  three temporal types, and `timestamps => 'object'` decodes them exactly (SPEC §10),
  including the 64-bit extremes of date and datetime and a table with date and datetime
  columns.
- **Packaging:** `.gitattributes` keeps tests/, bench/, stubs/, .github/ and the tool
  configuration out of the dist archive: 56 KB zipped instead of 120 KB.
- **Tests:** the vendored conformance vectors are spec 0.3's (311), including eight
  lz4-block vectors, which pass with ext-lz4 0.8.0 and liblz4 1.10.0; the LZ4 block check is
  also tested without the extension, and against what ext-lz4 compresses. The harness compared
  the date and datetime vectors at their 64-bit extremes with arithmetic that overflowed
  into floats; it now uses floor division. New tests time keys that share one hash against
  ordinary keys (wide tables, empty tables, both key caches), check that wide-table rows
  hold no PHP references, and check what `git archive` ships.

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
