# Field Operations - mobile app (Flutter, Android first)

Offline-first app for field agents. Everything the agent does is saved on the phone first and sent to the server
later, so a dead zone never loses work.

## What is in it

| Screen | Notes |
|---|---|
| Sign in | Email/username + password, token in the Android keystore, server address editable (Server settings) |
| Home | Today's progress, attendance, attention list, today's stops, sync status |
| My work / assignment detail | Today / upcoming / past, instructions, navigation to the store, accept, start visit |
| Routes | Today's stops in order; one tap to open the whole route in Google Maps |
| Attendance | Clock in / out (works offline; records the time you tapped) |
| Visit workspace | Check in (GPS), form, sales, on-shelf availability (OSA), merchandising, photos, issues, check out, submit |
| Forms | Every question type, conditional questions, required/range/pattern validation, signature pad, camera questions |
| Issues | Report from a visit or on its own |
| Notifications | From the server; read state syncs back |
| Sync center | Waiting / failed / sent, per-item retry, "retry all", "give up", re-download everything |
| Profile | Account, server address, sign out (warns if anything is unsent) |

## How offline works (the short version)

1. Screens only ever read the local SQLite database.
2. Every action writes to the database **and** adds an item to the outbox in one transaction.
3. The sync engine sends outbox items **oldest first**, one at a time, then downloads changes.
4. Each item has a client-generated UUID (`client_uuid`), so a retry can never create a duplicate on the server.
5. Failures are handled by kind - never dropped silently:

| Result | What the app does |
|---|---|
| No connection | Stops, keeps everything, tries again when online (not counted as a failed attempt) |
| 401 | Stops and asks you to sign in again; your work stays on the phone |
| 429 / 5xx | Retries with backoff (15 s, 30 s, 1 min ... up to 15 min), at most 8 times, then waits for a manual retry |
| 400 / 422 / 403 / 404 | Marked FAILED with the server's reason; later steps for that visit wait, other visits keep syncing |
| 409 | CONFLICT - unless the server already holds the result of that very step (a lost reply), which counts as done |

Photos are compressed (max 1600 px, quality 78), copied to the app's own storage, and deleted from the phone only
after the server confirms the upload. A submitted visit is locked; the server is authoritative for assignments,
forms and review decisions. See `docs/OFFLINE_SYNC.md` in the original package and `docs/MOBILE_APP.md` here.

## Run it (Windows)

Prerequisites: Flutter SDK (stable) on PATH, Android Studio with an Android SDK and an emulator, Docker stack running.

```powershell
cd D:\Projects\Meta_Intel_Technologies\mobile
flutter doctor                                  # fix anything marked with a red cross
flutter doctor --android-licenses               # accept all
powershell -ExecutionPolicy Bypass -File tools\setup-android.ps1
flutter analyze
flutter test
flutter run                                     # with an emulator started or a phone connected
```

The emulator reaches your PC at `10.0.2.2`, so the default server address `http://10.0.2.2:8080/api/v1` works as is.
On a real phone use `http://<your PC's LAN IP>:8080/api/v1` (Server settings on the sign-in screen) and allow port
8080 through Windows Firewall.

Demo agent logins (after `db:seed --class=DemoSeeder`): `demo.agent1@example.com` ... `demo.agent4@example.com`,
password `Demo@Field2026!`.

### Build an APK to install on a phone

```powershell
flutter build apk --release --dart-define=API_BASE_URL=https://your-server.example/api/v1
```

For production use HTTPS, remove `usesCleartextTraffic` from `AndroidManifest.xml`, and sign the release build
(https://docs.flutter.dev/deployment/android).

## Status - read this

This app was written without a Flutter/Dart toolchain (the build environment cannot reach pub.dev or the Dart SDK).
It has **not been compiled or run on a device**. What was done: a bracket/import consistency check
(`python tools/lint_dart.py`, `python tools/check_symbols.py`) and careful review. Expect to fix a handful of
compile errors the first time you run `flutter analyze`; send me the output and they are quick to resolve.
Unit tests are in `test/` (form rules, backoff, error mapping).

Known limits (planned next):
* Sync runs while the app is open and when it returns to the foreground or regains a connection; there is no
  Android background job yet (WorkManager).
* No push notifications (FCM) - notifications arrive on the next sync.
* Barcode questions accept typed input; scanning with the camera is not added yet.
* Photos are tagged with the last known location, not a fresh fix, so the camera is never delayed by GPS.
  A photo's metadata alone does not prove it is genuine.
* Offline-created data is held for only the assignment window the server sends (3 days back, 14 days ahead).
