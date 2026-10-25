# Development

All tooling runs in throwaway Docker containers (Node 22, Composer 2, PHP 8.2 and the Playwright image),
so nothing but Docker and `make` is needed on the host.

## Set up

Put the repository where a Nextcloud 32 development instance loads apps, for example a bind mount at
`custom_apps/audioannotations`, then:

```bash
make deps    # npm ci and composer install
make build   # js/ and css/ (not committed)
```

Enable the app in that instance with `occ app:enable audioannotations`. The instance needs `ffmpeg` and
`ffprobe` and background jobs running through cron.

During development, bump the version in `appinfo/info.xml` and `package.json` and run `occ upgrade`
whenever routes change or the browser should load new scripts.

## Checks and tests

| Command | What it runs |
| --- | --- |
| `make lint` | Nextcloud's PHP coding standard, Psalm (against `nextcloud/ocp`), ESLint and Stylelint |
| `make cs-fix` | The automatic fixes of the same checkers |
| `make test` | PHPUnit unit tests (no Nextcloud needed) |
| `make test-js` | Vitest unit tests for the front-end helpers |
| `make test-e2e` | Playwright end-to-end tests against a running instance, desktop and phone |

### End-to-end tests

The suite talks to a running Nextcloud (default `http://localhost:8090`, set `NC_URL` to change it) with
the official Playwright image on the host network. It needs:

- an `admin` account,
- passwords in the environment, or in a `.env.e2e` file in the repository root (not committed):

```bash
NC_ADMIN_PASSWORD=...
TEST_USER_PASSWORD=...
TEST_USER2_PASSWORD=...
```

Global setup creates the users `tester` and `tester2` when they are missing, uploads
`tests/fixtures/sample.mp3` (a generated 5:49 tone) to `AudioAnnotationsE2E/` in admin's files and shares that
folder read-only with both testers. Every test removes the notes, files and links it creates. Voice-note
tests use Chromium's fake microphone, so their recordings end up in tester's trash bin.

Narrow a run with `make test-e2e ARGS="--project=phone tests/e2e/notes.spec.js"`. The HTML report is
written to `build/playwright-report`.

## Screenshots

```bash
make screenshots   # rewrites screenshots/*.png
```

`tools/screenshots.mjs` creates two demo users (`maya`, `jordan`) with the password in `DEMO_PASSWORD`,
uploads a generated recording to maya's `Recordings` folder, shares it with jordan, replaces its notes with a
fixed demo set and photographs the app as jordan on desktop and on a phone. Disable the `firstrunwizard`
app on the instance first, or its welcome dialog appears in the pictures. If the waveform is not ready
yet (it is built by cron), run it again.

## Release

```bash
make appstore   # build/audioannotations-<version>.tar.gz
```

The archive contains `appinfo`, `lib`, the built `js` and `css`, `LICENSES`, `README.md` and
`CHANGELOG.md`, ready to unpack into `custom_apps`. Pushing a tag `v<version>` builds the same archive in
CI and attaches it to a GitHub release.
