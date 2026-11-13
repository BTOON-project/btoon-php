# Changelog

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
