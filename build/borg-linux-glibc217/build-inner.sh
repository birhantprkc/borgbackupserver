#!/bin/bash
# Runs inside the container. Packages the installed borg into a single binary
# and refuses to hand it over unless it really does target glibc 2.17.
set -euo pipefail

GLIBC_FLOOR="${GLIBC_FLOOR:-2.17}"
OUT_DIR=/out

cd /borg-src

echo "borg version: $(borg --version)"

# setuptools_scm generates _version.py at install time, so it exists in the
# installed package but not in the source tree — and PyInstaller resolves
# borg/__init__.py out of the source tree, where importing it then fails. Copy
# it across so the packaged binary can import its own version.
INSTALLED_BORG="$(python3 -c 'import borg, os; print(os.path.dirname(borg.__file__))')"
cp "$INSTALLED_BORG/_version.py" /borg-src/src/borg/_version.py
echo "version module: $(cat /borg-src/src/borg/_version.py | tr -d '\n')"

# borg's spec analyses the source tree, not site-packages, so the compiled
# extensions have to exist inside it — otherwise PyInstaller happily produces a
# binary that dies on "No module named borg.crypto.low_level" at startup.
python3 setup.py build_ext --inplace

# borg's own spec knows which hidden imports and data files it needs; hand-
# rolling a pyinstaller invocation gets this subtly wrong.
pyinstaller --clean --distpath /tmp/dist --workpath /tmp/work scripts/borg.exe.spec

BIN=/tmp/dist/borg.exe
[ -f "$BIN" ] || { echo "ERROR: pyinstaller produced no binary at $BIN"; exit 1; }

strip "$BIN" || true

# The guarantee this whole image exists to make. Upstream's own checker reads
# the symbol versions out of the binary; if anything linked against a newer
# glibc, the build fails here rather than on a customer's CentOS 7 box.
echo "Checking glibc floor (${GLIBC_FLOOR})..."
python3 scripts/glibc_check.py "$GLIBC_FLOOR" "$BIN"

# Smoke test: a binary that can't list its own help is not shippable.
"$BIN" --version
"$BIN" --help > /dev/null

mkdir -p "$OUT_DIR"
cp "$BIN" "$OUT_DIR/borg-linux-glibc217-x86_64"
chmod 755 "$OUT_DIR/borg-linux-glibc217-x86_64"

cd "$OUT_DIR"
sha256sum borg-linux-glibc217-x86_64 > SHA256SUMS

echo
echo "Built: $(ls -lh borg-linux-glibc217-x86_64 | awk '{print $5}')"
cat SHA256SUMS
