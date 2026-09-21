#!/usr/bin/env python3
"""
Build a distributable WordPress plugin zip.

Ships ONLY what the plugin needs at runtime. Everything used to develop, test or
analyse it - tests, docs, CI config, composer/npm manifests, the wp-env setup -
stays out. The plugin has zero Composer runtime dependencies (see CLAUDE.md), so
there is no vendor/ directory to think about.

    python tools/build-zip.py
    python tools/build-zip.py --out C:\\Users\\harsh\\Desktop

Produces  fieldpilot-for-acf-<version>.zip  whose single top-level folder is
`fieldpilot-for-acf/`, which is what WordPress expects from an uploaded plugin.
"""

from __future__ import annotations

import argparse
import fnmatch
import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SLUG = "fieldpilot-for-acf"

# Everything that ships, and nothing else.
INCLUDE = [
    "fieldpilot-for-acf.php",
    "uninstall.php",
    "readme.txt",
    "includes/**/*.php",
    "assets/css/*.css",
    "assets/js/*.js",
    "schemas/*.json",
    "languages/*.mo",
    "languages/*.po",
    "languages/*.pot",
]

# Belt and braces: even if a glob above widened, these never ship.
NEVER = [
    "*/tests/*", "tests/*",
    "*/node_modules/*", "*/vendor/*",
    "*.dist", "*.lock", "*.log", "*.zip",
    ".*", "*/.*",
    "composer.json", "package.json", "package-lock.json",
    "CLAUDE.md",
    "*/docs/*", "docs/*",
    "*/tools/*", "tools/*",
    "*.map",
]


def version() -> str:
    header = (ROOT / "fieldpilot-for-acf.php").read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*(.+)$", header, re.MULTILINE)
    return match.group(1).strip() if match else "0.0.0"


def excluded(rel: str) -> bool:
    return any(fnmatch.fnmatch(rel, pattern) for pattern in NEVER)


def collect() -> list[pathlib.Path]:
    seen: set[pathlib.Path] = set()

    for pattern in INCLUDE:
        for path in sorted(ROOT.glob(pattern)):
            if not path.is_file():
                continue

            rel = path.relative_to(ROOT).as_posix()

            if excluded(rel):
                print(f"  ! skipped by NEVER rule: {rel}")
                continue

            seen.add(path)

    return sorted(seen)


def main() -> int:
    parser = argparse.ArgumentParser(description="Build the distributable plugin zip.")
    parser.add_argument("--out", default=str(ROOT), help="Directory to write the zip into.")
    args = parser.parse_args()

    out_dir = pathlib.Path(args.out).expanduser().resolve()
    out_dir.mkdir(parents=True, exist_ok=True)

    ver = version()
    target = out_dir / f"{SLUG}-{ver}.zip"

    files = collect()

    if not files:
        print("Nothing to package - are you running this from the plugin root?")
        return 1

    # Directories that get a silence file, the WordPress convention against
    # directory listing on servers with autoindex enabled.
    silence_dirs = {pathlib.PurePosixPath(f.relative_to(ROOT).as_posix()).parent for f in files}
    silence_dirs = {d for d in silence_dirs if str(d) != "."}

    if target.exists():
        target.unlink()

    total = 0

    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            rel = path.relative_to(ROOT).as_posix()
            archive.write(path, f"{SLUG}/{rel}")
            total += path.stat().st_size

        for directory in sorted(silence_dirs):
            archive.writestr(f"{SLUG}/{directory}/index.php", "<?php\n// Silence is golden.\n")

    print(f"\n{target}")
    print(f"  {len(files)} files, {total / 1024:.0f} KB uncompressed, "
          f"{target.stat().st_size / 1024:.0f} KB zipped\n")

    print("Contents:")
    counts: dict[str, int] = {}
    for path in files:
        top = path.relative_to(ROOT).parts[0]
        top = top if path.relative_to(ROOT).parent != pathlib.Path(".") else "(root)"
        counts[top] = counts.get(top, 0) + 1

    for name, count in sorted(counts.items()):
        print(f"  {name:<12} {count} file(s)")

    print("\nExcluded: tests, docs, tools, CI config, composer/npm manifests, wp-env setup.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
