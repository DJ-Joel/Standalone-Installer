# build/

Scripts that run **before** compiling the Inno Setup installer, to fetch
third-party binaries the installer needs but that shouldn't be committed to
this repo (large, and git never truly forgets a binary once committed).

`fetch-dependencies.ps1` handles all three:
  - PHP (portable, NTS x64, with pdo_sqlite) - downloaded, hash-verified
  - WinSW (service wrapper) - downloaded, hash-verified
  - qrencode.exe - **built from source via vcpkg**, not downloaded. No
    trustworthy prebuilt Windows binary exists anywhere for this one - see
    the script's own header comment for the full reasoning. This step needs
    Visual Studio Build Tools (MSVC) installed on whoever runs the script -
    a real prerequisite the other two don't have.

Still outstanding: the PHP and WinSW entries have real, verified download
URLs, but their pinned SHA256 hashes are still placeholders - they need to be
filled in after one manually-verified download of each (see the
"REQUIRES SETUP" markers in the script).

Everything this script produces should be gitignored. Each bundled
dependency's own license file travels with it into deps\tools\ - qrencode's
is copied automatically from vcpkg's own package metadata during the build.
