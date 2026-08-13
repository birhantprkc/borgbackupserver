# Builds a standalone borg binary that runs on glibc 2.17 and newer.
#
# Why this exists: upstream borg's release binaries target a much newer glibc,
# so they refuse to start on older enterprise Linux (CentOS/RHEL 7, Amazon
# Linux 2, and the long-lived appliances people back up). BBS serves this
# binary from public/borg/<version>/ to those clients instead.
#
# The base image is PyPA's manylinux2014, which is CentOS 7 — glibc 2.17 —
# with a maintained toolchain and Python builds, and repositories that still
# work now that CentOS 7 itself is EOL and its mirrors are gone. Building
# against the oldest glibc you intend to support is the whole trick: glibc is
# backward compatible, so a binary linked here runs everywhere newer.
FROM quay.io/pypa/manylinux2014_x86_64

ARG BORG_VERSION=1.4.5
ARG OPENSSL_VERSION=1.1.1w
ARG PYTHON_VERSION=3.11.16

# CentOS 7 ships OpenSSL 1.0.2; borg 1.4 needs 1.1.1+. Built into /usr/local
# so the borg C extensions link against it rather than the system one.
RUN curl -fsSL "https://www.openssl.org/source/openssl-${OPENSSL_VERSION}.tar.gz" -o /tmp/openssl.tar.gz \
 && tar xzf /tmp/openssl.tar.gz -C /tmp \
 && cd "/tmp/openssl-${OPENSSL_VERSION}" \
 && ./config --prefix=/usr/local --openssldir=/usr/local/ssl shared zlib \
 && make -j"$(nproc)" \
 && make install_sw \
 && ldconfig \
 && rm -rf /tmp/openssl*

# borg's other native dependencies, plus what CPython needs to build a complete
# standard library. libffi-devel in particular is not optional: without it
# Python builds happily but silently omits _ctypes, and the failure only shows
# up much later when PyInstaller imports it.
RUN yum install -y \
        libacl-devel zlib-devel \
        libffi-devel bzip2-devel xz-devel sqlite-devel readline-devel ncurses-devel gdbm-devel uuid-devel \
 && yum clean all

RUN curl -fsSL https://github.com/lz4/lz4/archive/refs/tags/v1.9.4.tar.gz -o /tmp/lz4.tar.gz \
 && tar xzf /tmp/lz4.tar.gz -C /tmp && cd /tmp/lz4-1.9.4 \
 && make -j"$(nproc)" && make install PREFIX=/usr/local && ldconfig && rm -rf /tmp/lz4*

RUN curl -fsSL https://github.com/facebook/zstd/releases/download/v1.5.6/zstd-1.5.6.tar.gz -o /tmp/zstd.tar.gz \
 && tar xzf /tmp/zstd.tar.gz -C /tmp && cd /tmp/zstd-1.5.6 \
 && make -j"$(nproc)" && make install PREFIX=/usr/local && ldconfig && rm -rf /tmp/zstd*

RUN curl -fsSL https://github.com/Cyan4973/xxHash/archive/refs/tags/v0.8.2.tar.gz -o /tmp/xxhash.tar.gz \
 && tar xzf /tmp/xxhash.tar.gz -C /tmp && cd /tmp/xxHash-0.8.2 \
 && make -j"$(nproc)" && make install PREFIX=/usr/local && ldconfig && rm -rf /tmp/xxhash* /tmp/xxHash*

# PyInstaller needs a shared libpython, and the interpreters shipped in the
# manylinux image are static-only — it refuses to start otherwise. So Python is
# built here too, with --enable-shared and against the OpenSSL above rather than
# CentOS 7's 1.0.2.
RUN curl -fsSL "https://www.python.org/ftp/python/${PYTHON_VERSION}/Python-${PYTHON_VERSION}.tgz" -o /tmp/python.tgz \
 && tar xzf /tmp/python.tgz -C /tmp \
 && cd "/tmp/Python-${PYTHON_VERSION}" \
 && ./configure --prefix=/usr/local/python311 --enable-shared --enable-optimizations \
      --with-openssl=/usr/local LDFLAGS="-Wl,-rpath,/usr/local/python311/lib -Wl,-rpath,/usr/local/lib" \
 && make -j"$(nproc)" \
 && make altinstall \
 && ln -sf /usr/local/python311/bin/python3.11 /usr/local/python311/bin/python3 \
 && ln -sf /usr/local/python311/bin/pip3.11 /usr/local/python311/bin/pip3 \
 && ldconfig \
 && rm -rf /tmp/python* /tmp/Python* \
 && /usr/local/python311/bin/python3.11 -c "import ctypes, ssl, lzma, bz2, sqlite3; print('stdlib ok', ssl.OPENSSL_VERSION)"

ENV PATH="/usr/local/python311/bin:${PATH}" \
    PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig:/usr/lib64/pkgconfig:/usr/lib/pkgconfig:/usr/share/pkgconfig \
    LD_LIBRARY_PATH=/usr/local/lib:/usr/local/lib64

# Point borg's build at each dependency explicitly. It prefers pkg-config, and
# CentOS 7's libacl-devel ships no libacl.pc at all — only the header and the
# library — so without BORG_LIBACL_PREFIX the build stops there. The rest are
# from-source installs under /usr/local and are named for the same reason: so a
# missing .pc is a non-event rather than a confusing failure late in the build.
ENV BORG_OPENSSL_PREFIX=/usr/local \
    BORG_LIBLZ4_PREFIX=/usr/local \
    BORG_LIBZSTD_PREFIX=/usr/local \
    BORG_LIBXXHASH_PREFIX=/usr/local \
    BORG_LIBACL_PREFIX=/usr

# Build borg from the release sdist rather than a git checkout, so the version
# string in the binary is the released one and nothing depends on git state.
RUN python3 -c "import ssl; print(ssl.OPENSSL_VERSION)" \
 && pip3 install --no-cache-dir -U pip setuptools wheel pkgconfig Cython pyinstaller \
 && pip3 install --no-cache-dir --no-binary :all: "borgbackup==${BORG_VERSION}"

# borg ships its own PyInstaller spec and a glibc floor checker; use both
# rather than hand-rolling the packaging.
RUN curl -fsSL "https://github.com/borgbackup/borg/archive/refs/tags/${BORG_VERSION}.tar.gz" -o /tmp/borg-src.tar.gz \
 && mkdir -p /borg-src && tar xzf /tmp/borg-src.tar.gz -C /borg-src --strip-components=1 \
 && rm /tmp/borg-src.tar.gz

WORKDIR /borg-src
COPY build-inner.sh /usr/local/bin/build-inner.sh
RUN chmod +x /usr/local/bin/build-inner.sh
CMD ["/usr/local/bin/build-inner.sh"]
