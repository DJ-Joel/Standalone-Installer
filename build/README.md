# build/

Scripts that run **before** compiling the Inno Setup installer, to fetch
third-party binaries the installer needs but that shouldn't be committed to
this repo (large, and git never truly forgets a binary once committed).

Not yet written - `fetch-dependencies.ps1` needs to land here, downloading
pinned, known-good versions of:
  - A portable PHP build (with pdo_sqlite enabled)
  - NSSM (the service wrapper)
  - qrencode.exe (LGPL 2.1, https://fukuchi.org/works/qrencode/) - decided,
    see scripts/generate-qr.ps1. Still needs a specific trustworthy build
    pinned here (ideally built from a pinned source commit ourselves, or
    sourced from vcpkg's build pipeline, rather than trusting an arbitrary
    prebuilt binary found online) with a checksum this script verifies
    before use.

Everything this script downloads should be gitignored. Each bundled
dependency's own license file needs to travel with the installed app - see
tools\ once fetch-dependencies.ps1 exists.
