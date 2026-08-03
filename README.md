# Standalone-Installer

A GUI installer that turns a Windows machine into a ready-to-go OpenKJ singer
request server, without requiring the KJ to manually install WAMP, edit PHP
files by hand, or configure a web server at all.

This is a from-scratch installer product. It bundles a copy of the
[StandaloneRequestServer](https://github.com/DJ-Joel/StandaloneRequestServer)
PHP application as of its final released state, and going forward all
development of that request-server code happens **here**, not in the
original repo (which is frozen, not deleted).

## How it works

- No Apache, no MySQL. The request server is PHP + SQLite only - a portable,
  bundled PHP runs it directly via `php -S` as a Windows service (via NSSM),
  not through a full web server stack.
- The installer detects the machine's LAN IP, opens a Windows Firewall rule
  for the chosen port, and generates a QR code (fully offline, via a bundled
  `qrencode.exe`) singers can scan to reach it.
- It writes OpenKJ's own settings file directly, so OpenKJ and the request
  server come up already paired - no copying a URL between two programs by
  hand.
- The venue's database lives in `%ProgramData%`, never inside the app's own
  install folder, so upgrading the app can never wipe a venue's singer
  accounts, favorites, or chat history.

## Repo layout

```
requests-app/    the PHP application itself (MIT-licensed, notice preserved)
installer/       the Inno Setup script
scripts/         PowerShell helpers the installer runs (LAN IP, QR, OpenKJ
                 settings) - each also usable standalone for troubleshooting
build/           fetches pinned third-party binaries (PHP, NSSM, qrencode)
                 before compiling - nothing here is committed to git
assets/          installer icon, wizard images
```

## Status

Early scaffolding. See each directory's own README for what's built vs. still
outstanding.
