# Audio Annotations

A Nextcloud app for reviewing recordings. Highlight a range of an audio file and attach a text or voice
note to it, or leave a General Comment on the whole recording. Everyone the file is shared with can read
the notes, reply and resolve them.

## Features

- **Waveform player.** Audio files open in a waveform player that replaces the Viewer's built-in audio
  player for logged-in users. Note bars sit above the waveform; public share links keep the built-in player.
- **Selecting a range.** Drag across the waveform, use Mark start / Mark end while it plays, or tap a note.
  A new range shows a small × that clears it. With Loop off, playback stops at the end of the range; Play
  from outside the range starts at its beginning.
- **Zoom.** Pinch on touch screens; slider or Ctrl/Cmd + scroll on desktop. While zoomed, an overview
  strip under the waveform moves around the recording.
- **Text and voice notes.** A box at the bottom of the player takes text, or a voice note from the
  microphone. Without a range, a note covers 5 seconds from where typing started; at 0:00 it becomes a
  General Comment. Notes can be private to their author.
- **Notes list.** Starts collapsed; selecting a note shows just that note with its replies.
- **Files sidebar.** An Annotations tab lists the same notes with replies, edit, resolve, delete and
  export to Markdown or an Audacity label track.
- **Keyboard** (player focused): Space play/pause, `[` and `]` mark start and end, `L` loop, arrow keys
  skip 5 seconds.
- **Phones.** Layout, gestures and recording work in mobile browsers (tested on Android; iPhone untested).

## Requirements

- Nextcloud 32 (the player relies on internals of the Viewer app, so newer versions are not enabled yet)
- PHP 8.1 or later
- `ffmpeg` and `ffprobe` on the server, for waveforms and for repairing browser voice recordings
- Background jobs running through cron, which build the waveforms
- HTTPS for recording voice notes (browsers only allow the microphone on secure pages)

## Install

1. Download `audioannotations-<version>.tar.gz` from the [releases](https://github.com/corbindk/audioannotations/releases).
2. Unpack it into your Nextcloud's `custom_apps` (or `apps`) folder, so the app lives in `custom_apps/audioannotations`.
3. Enable it: `sudo -u www-data php occ app:enable audioannotations`.

## How it works

- Notes are stored in two tables (`audioannotations_notes`, `audioannotations_replies`). A note belongs to the audio
  file by its file id, so notes follow the file when it is moved or renamed.
- Voice notes are saved in the author's own files under `Audio Annotations/<recording name>/`, so they count
  against the author's quota and are covered by their backups. Others play them through the note, never
  through a share.
- Waveforms are built by a background job and cached in app data. Until then the player shows a flat
  line and still plays.
- Notes on files that are deleted for good are removed by a daily job; a deleted user's notes go with them.
- The app allows the microphone and `blob:` media on Nextcloud pages, which Nextcloud's default security
  policy forbids, so that voice notes can be recorded and previewed.

## API

OCS, under `/ocs/v2.php/apps/audioannotations/api/v1`, header `OCS-APIRequest: true`:

| Method | Path |
| --- | --- |
| GET | `/files/{fileId}/notes` |
| POST | `/files/{fileId}/notes` (`startMs`, `endMs`, `body`, `color`, `visibility`; `startMs` = `endMs` = 0 for a General Comment) |
| PATCH | `/notes/{id}` (any of `startMs`, `endMs`, `body`, `color`, `visibility`, `resolved`) |
| DELETE | `/notes/{id}` |
| POST | `/files/{fileId}/voice-notes` (multipart: `audio`, `startMs`, `endMs`, `durationMs`, `caption`, `visibility`) |
| POST | `/notes/{id}/replies` (`body`) |
| DELETE | `/replies/{id}` |
| GET | `/files/{fileId}/waveform` (200 with peaks, or 202 while a background job builds them) |

Voice recordings stream from `GET /apps/audioannotations/voice/{id}` (Range supported).
Export: `GET /apps/audioannotations/export/{fileId}?format=md|audacity`.

## Development

See [docs/development.md](docs/development.md) for building, the checkers and the test suites.

## License

AGPL-3.0-or-later. See [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt).
