#!/usr/bin/env python3
"""
Writes tests/Fixtures/reference-cases.json: extra canonical-encoding cases for the
PHP test suite, produced by the *independent* Python encoder in btoon-core
(tests/vectors/generate.py). They cover header-size boundaries, every tabular
type code in one table, UTF-8 and integer-like key order, large containers, and
other edges beyond the conformance vectors.

    python3 tests/Fixtures/generate-reference-cases.py [path/to/btoon-core]

Needs msgpack (pip install msgpack, or: uv run --with msgpack python ...).
Read-only towards btoon-core: no bytecode cache is written next to generate.py.
"""
from __future__ import annotations

import sys

sys.dont_write_bytecode = True

import hashlib  # noqa: E402
import importlib.util  # noqa: E402
import json  # noqa: E402
from pathlib import Path  # noqa: E402

HERE = Path(__file__).resolve().parent
CORE = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE.parents[2] / "btoon-core"
spec = importlib.util.spec_from_file_location("btoon_generate", CORE / "tests/vectors/generate.py")
gen = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gen)
TS, Date, DateTime, F32, F64, Ext = gen.TS, gen.Date, gen.DateTime, gen.F32Vec, gen.F64Vec, gen.Ext

CASES: list[dict] = []


def case(id_: str, value) -> None:
    CASES.append({"id": id_, "hex": gen.enc(value).hex(), "value": gen.typed(value)})


def recipe(id_: str, kind: str, n: int, value) -> None:
    """Large values: both sides build them from (kind, n) and compare a digest."""
    data = gen.enc(value)
    CASES.append({"id": id_, "recipe": kind, "n": n, "length": len(data),
                  "sha256": hashlib.sha256(data).hexdigest()})


row = {"n": None, "b": True, "i": -5, "u": 2**63, "f": 1.5, "s": "x", "bin": b"\x01", "arr": [1],
       "m": {"a": 1}, "ts": TS(0)}
case("tabular/all-type-codes", [row] * 5)
case("tabular/type-code-9-variants", [{"big": 2**64, "neg": -(2**63) - 1, "d": Date(1), "dt": DateTime(1),
                                       "v32": F32([1.0]), "v64": F64([2.0]), "e": Ext(7, b"\x01"),
                                       "tso": TS(1, 2, 60)}] * 6)
case("tabular/type-code-from-first-row", [{"x": None if i == 0 else i, "y": "s" if i == 0 else 1.5}
                                          for i in range(10)])
case("tabular/type-code-int64-edges", [{"lo": -(2**63), "hi": 2**63 - 1, "u": 2**64 - 1}] * 6)
case("tabular/utf8-columns-7", [{"é": i, "z": i, "Z": i} for i in range(7)])
case("tabular/nested-cells-8", [{"k": [i, {"x": i}], "v": None if i % 2 else "s"} for i in range(8)])
case("tabular/key-31", [{"k" * 31: i} for i in range(2)])
case("tabular/key-32", [{"k" * 32: i} for i in range(2)])
case("tabular/16-columns", [{f"c{c:02d}": r for c in range(16)} for r in range(4)])
case("tabular/ext16-header", [{"id": i, "name": f"user{i}"} for i in range(40)])
case("tabular/empty-map-cells", [{"a": {}, "b": []} for _ in range(12)])
case("tabular/rows-in-map-in-tabular", [{"t": [{"q": j} for j in range(10)]} for _ in range(10)])

case("map/utf8-order-non-bmp", {"\U0001F600": 1, "￿": 2, "": 3, "z": 4})
case("map/integer-like-keys", {"10": 1, "9": 2, "a": 3, "-1": 4, "01": 5, "": 6, "1": 7})
case("map/key-31-32", {"k" * 31: 1, "k" * 32: 2})
case("map/nested-empty", {"a": {}, "b": [], "c": [{}], "d": {"e": {}}})

for name, (s, ns) in {"u32-max": (2**32 - 1, 0), "2^32": (2**32, 0), "2^34-1": (2**34 - 1, 0),
                      "2^34": (2**34, 0), "int64-min": (-(2**63), 0), "int64-max": (2**63 - 1, 999999999),
                      "1ns": (0, 1)}.items():
    case(f"timestamp/{name}", TS(s, ns))
case("timestamp/offset+1080", TS(-5, 7, 1080))
case("timestamp/offset-1080", TS(5, 0, -1080))
case("timestamp/offset-0", TS(0, 0, 0))

for n in (0, 1, 2, 3, 4, 5, 8, 15, 16, 17, 255, 256):
    case(f"ext/length-{n}", Ext(9, bytes(range(256))[:n]))
case("ext/type-minus-128", Ext(-128, b"\x00"))
case("ext/type-minus-7", Ext(-7, b""))

for v in (2**53, 2**53 + 1, -(2**53), 1e-310, 1.7976931348623157e308, -1e-300, 0.1):
    case(f"float/{v!r}", float(v))
for v in (2**64, -(2**64), 2**64 + 1, -(2**64) - 1, 2**127, -(2**127), 2**1000, -(10**50) + 1, 2**71 - 1):
    case(f"bigint/{v}", v)

recipe("str/65535", "str", 65535, "x" * 65535)
recipe("str/65536-str32", "str", 65536, "x" * 65536)
recipe("bin/65535", "bin", 65535, bytes(65535))
recipe("bin/65536-bin32", "bin", 65536, bytes(65536))
recipe("array/65535", "array", 65535, [0] * 65535)
recipe("array/65536-array32", "array", 65536, [0] * 65536)
recipe("map/65536-map32", "map", 65536, {f"k{i:05d}": i for i in range(65536)})
recipe("ext/65535", "ext", 65535, Ext(1, bytes(65535)))
recipe("ext/65536-ext32", "ext", 65536, Ext(1, bytes(65536)))
recipe("tabular/ext32-header", "table", 5000, [{"id": i, "name": f"user{i}"} for i in range(5000)])

out = {"generated_by": "tests/Fixtures/generate-reference-cases.py (btoon-core generate.py encoder)",
       "cases": CASES}
(HERE / "reference-cases.json").write_text(json.dumps(out, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")
print(f"wrote {len(CASES)} cases")
