#!/bin/bash
# Build a glibc 2.17 borg binary and drop it where BBS serves it from.
#
#   ./build.sh 1.4.5
#
# Produces public/borg/<version>/borg-linux-glibc217-x86_64 plus SHA256SUMS.
# Signing is a separate, manual step — see README.md.
#
# Must run on x86_64. On an arm64 machine Docker will emulate, which works but
# turns a few minutes into the better part of an hour.
set -euo pipefail

BORG_VERSION="${1:-}"
if [ -z "$BORG_VERSION" ]; then
    echo "Usage: $0 <borg-version>    e.g. $0 1.4.5"
    exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
DEST="$REPO_ROOT/public/borg/$BORG_VERSION"
IMAGE="bbs-borg-builder:$BORG_VERSION"

if [ "$(uname -m)" != "x86_64" ]; then
    echo "Warning: building x86_64 under emulation on $(uname -m) — this will be slow."
fi

echo "==> Building image for borg $BORG_VERSION"
docker build --platform linux/amd64 \
    --build-arg "BORG_VERSION=$BORG_VERSION" \
    -t "$IMAGE" "$HERE"

echo "==> Running the build"
mkdir -p "$DEST"
docker run --rm --platform linux/amd64 -v "$DEST:/out" "$IMAGE"

echo
echo "==> Done: $DEST"
ls -l "$DEST"
echo
echo "Next:"
echo "  1. Test it on an actual old-glibc host before shipping."
echo "  2. Sign it and copy in the public key (see README.md)."
echo "  3. Commit public/borg/$BORG_VERSION/ and deploy."
