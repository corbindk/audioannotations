# How it works

## Storage and jobs

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
