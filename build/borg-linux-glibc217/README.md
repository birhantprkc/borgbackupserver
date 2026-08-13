# borg for old glibc (2.17+)

Upstream borg's release binaries target a much newer glibc than long-lived
enterprise Linux ships — CentOS/RHEL 7, Amazon Linux 2, and the appliances
people keep backing up. They fail to start there. BBS serves its own build to
those clients instead, out of `public/borg/<version>/`.

The 1.4.3 and 1.4.4 binaries in that directory were built by hand on a live
CentOS 7 server, with no script and no record beyond the commit message —
which is also wrong: it says Python 3.11, and the environment that actually
produced 1.4.3 has Python 3.10.14. That box still exists but is a production
machine running GCC 4.8.5, which is too old to compile borg 1.4.5 at all
(Cython now emits C99). This directory replaces it.

1.4.5 was built with what's here and verified on a real CentOS 7 host:
`init`, `create` and `list` all work against glibc 2.17.

## Building

```bash
./build.sh 1.4.5
```

Runs on any x86_64 machine with Docker and writes into
`public/borg/1.4.5/`. On arm64 Docker emulates x86_64 — it works, but budget
most of an hour instead of a few minutes.

The build refuses to produce a binary that doesn't meet the glibc floor: it
runs borg's own `scripts/glibc_check.py`, which reads the symbol versions back
out of the finished binary. That check is the point of the whole exercise, so
if it fails, do not ship the result — find out which dependency pulled in a
newer glibc.

## How it works

The base image is PyPA's `manylinux2014_x86_64`, which is CentOS 7 (glibc
2.17) with a maintained toolchain and working repositories — CentOS 7 itself
is EOL and its mirrors are gone, so installing from `centos:7` now means
pointing yum at vault.centos.org. Building against the oldest glibc you intend
to support is the entire trick: glibc is backward compatible, so a binary
linked there runs on everything newer.

On top of that the image builds what CentOS 7 is too old to provide: OpenSSL
1.1.1 (it ships 1.0.2, borg 1.4 needs 1.1.1+), lz4, zstd and xxhash. Borg is
installed from the release sdist so the version string is the real one, then
packaged with borg's own PyInstaller spec rather than a hand-written
invocation.

## What the server expects

`BorgVersionService` scans `public/borg/` for version directories and matches
binaries by filename, so the naming is load-bearing:

    public/borg/<version>/
        borg-linux-glibc217-x86_64        # borg-{platform}-glibc{NNN}-{arch}
        borg-linux-glibc217-x86_64.asc    # detached GPG signature
        marcpope-public-key.asc           # the signing key
        SHA256SUMS

A client is offered the binary when its reported glibc is greater than or
equal to the number in the filename. Version directories are sorted newest
first with `version_compare`.

Note that the agent does not currently verify the signature or the checksum —
it downloads over HTTPS from the BBS server it is already authenticated to.
The `.asc` and `SHA256SUMS` files are there so anyone can verify the binary
independently, which matters for something this privileged.

## Signing

Not automated, deliberately — the key should not be sitting on a build host.
After building, on a machine that holds the key:

```bash
cd public/borg/<version>
gpg --detach-sign --armor borg-linux-glibc217-x86_64
gpg --armor --export <key-id> > marcpope-public-key.asc
sha256sum -c SHA256SUMS
```

## Before shipping a new version

1. Check the glibc floor passed (the build enforces it, but read the output).
2. Run it on a genuinely old host — the check proves the symbols, not that
   borg works.
3. `borg --version` should report the version you asked for.
4. Commit the whole directory, then deploy.

## Notes

The `--enable-optimizations` flag on the Python build runs CPython's test
suite for PGO and is most of the build time. It buys nothing measurable for a
backup binary — drop it if you want faster rebuilds.

The 1.4.5 binary is 24 MB against 1.4.4's 10 MB. Both work; the difference is
in how PyInstaller bundles, not in borg. Worth a look if size ever matters.
