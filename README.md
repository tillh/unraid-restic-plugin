# Unraid Restic Plugin

Minimal Unraid 7 plugin that installs and manages the latest stable `restic` release.

Do not use `v2026.03.31.1`. That release had an invalid `.plg` XML file and Unraid rejected it during installation. Use `v2026.03.31.2` or newer.

The plugin keeps a persisted copy of the binary on the flash drive, exposes it as `/usr/local/bin/restic`, and adds a very small settings page that shows:

- installed version
- latest available version
- an install/update button when needed
- a remove button for the managed binary

The goal is simple: make `restic` available for shell usage and for the User Scripts plugin without dragging in a larger backup UI.

## What It Does

- Installs the latest stable restic release from `restic/restic` on first install
- Persists the binary at `/boot/config/plugins/restic/bin/restic`
- Restores `/usr/local/bin/restic` on reboot
- Verifies downloads against the published `SHA256SUMS`
- Exposes a helper command at `/usr/local/sbin/restic-manager`
- Adds a small Unraid settings page at `Settings -> restic`

## What It Does Not Do

- It does not configure repositories, schedules, retention policies, or notifications
- It does not add a full backup workflow UI
- The page's `Remove restic` button removes the managed binary, not the plugin itself

Plugin uninstall is still handled from the Unraid Plugins page.

## Repo Layout

`source/`
Contains the files that go into the plugin package.

`templates/restic.plg.in`
Template used to generate the final `.plg`.

`build.sh`
Builds the `.txz` package and renders the `.plg`.

`dist/`
Generated output directory for the installable artifacts.

## Build

1. Set the base URL that will host the finished files.
2. Run the build script.

Example:

```bash
ARTIFACT_BASE_URL="https://raw.githubusercontent.com/<your-user>/<your-repo>/main/dist" \
SUPPORT_URL="https://github.com/<your-user>/<your-repo>" \
./build.sh
```

That generates:

- `dist/restic.plg`
- `dist/packages/restic-<version>-noarch-1.txz`

## Publish

Commit and push the generated `dist/` artifacts to the URL you used in `ARTIFACT_BASE_URL`.

If you want the `.plg` to install from GitHub release assets instead of raw branch files, build like this:

```bash
VERSION="$(cat VERSION)"
ARTIFACT_BASE_URL="https://github.com/<your-user>/<your-repo>/releases/download/v${VERSION}" \
PACKAGE_SUBDIR="" \
SUPPORT_URL="https://github.com/<your-user>/<your-repo>" \
./build.sh
```

In that mode, upload these two files as release assets:

- `dist/restic.plg`
- `dist/packages/restic-<version>-noarch-1.txz`

This repo also includes a GitHub Actions workflow at `.github/workflows/release.yml` that creates or updates the tagged release and uploads those two assets when `main` is pushed with a matching `VERSION` and `v<version>` tag already present.

After that, install the plugin in Unraid from:

```text
https://github.com/<your-user>/<your-repo>/releases/download/v<version>/restic.plg
```

## Runtime Behavior

- First install: downloads the latest stable restic release for the current architecture
- Reboot: recreates `/usr/local/bin/restic` from the persisted binary
- Update from UI: downloads the current latest stable release and swaps the managed binary
- Remove from UI: removes the managed binary and leaves the plugin installed so you can reinstall later

## Commands

Once installed:

```bash
restic version
restic-manager status
restic-manager update
restic-manager remove
```

For User Scripts, `restic` is available at:

```text
/usr/local/bin/restic
```

## Notes

- This plugin assumes ownership of `/usr/local/bin/restic`. If that path already contains an unmanaged binary, the plugin refuses to overwrite it.
- Install and update require internet access from the Unraid server and access to GitHub release assets.
- The manager currently supports the common Linux architectures that restic publishes in its release assets.

## Sources

- restic releases: https://github.com/restic/restic/releases
- restic installation docs: https://restic.readthedocs.io/en/latest/020_installation.html
