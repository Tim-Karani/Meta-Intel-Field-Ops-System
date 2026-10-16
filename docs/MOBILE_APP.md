# Mobile app and synchronization

The Flutter app lives in `mobile/` (see `mobile/README.md` to run it). This note records how it talks to the API.

## Read side - two endpoints

`GET /api/v1/sync/bootstrap` - everything an agent needs to work offline:

* `assignments` for a window of 3 days back to 14 days ahead (with campaign, client, activity, location incl. coordinates and geofence radius, supervisor)
* `forms` - the full structure (sections, questions, options, conditions) of every form those assignments use
* `products` - active products of the agent's campaigns, each with `campaign_ids`
* `visits` - the agent's own visits in the window, with `checkin`, `checkout`, and `answers`
* `notifications` - latest 50
* `assignment_ids` - the full current id list, so the app can detect assignments that were removed or reassigned
* `server_time`, `window`, `full`

`GET /api/v1/sync/changes?since=<ISO time>` - the same shapes restricted to rows changed after `since` (2 s overlap).
Products and `assignment_ids` are always sent whole. Both go through `AccessScope`, so an agent only receives their own rows.

## Write side - the existing idempotent endpoints

| Queued operation | Request |
|---|---|
| assignment_accept | `POST /assignments/{id}/accept` |
| attendance_clock_in / out | `POST /attendance/clock-in` (idempotent per day and `client_uuid`), `/clock-out` |
| visit_create | `POST /visits` `{assignment_id, client_uuid, device_id}` - returns the existing visit on replay |
| visit_checkin / checkout | `POST /visits/{id}/check-in` / `check-out` with GPS evidence and `device_ts` |
| visit_responses | `PUT /visits/{id}/responses` `{answers}` (the app sends the newest answers when the item runs) |
| visit_sale / osa / merch | `POST /visits/{id}/sales`, `PUT /osa`, `POST /merchandising` with `client_uuid` |
| photo_upload | `POST /visits/{id}/photos` (multipart, `client_uuid`) |
| visit_submit | `POST /visits/{id}/submit` |
| issue_create | `POST /issues` with `client_uuid` |

The server decides what a check-in means (`GpsValidator`); the app only sends raw readings, including the time the
reading was taken (`device_ts`), so an offline check-in is judged fairly when it syncs. There is deliberately no
"offline replay" flag the client can set.

## Ordering and dependencies

Outbox items are sent in the order they were created. A visit's `visit_create` always precedes its other steps, and a
step that fails permanently (FAILED / CONFLICT) blocks only **that visit's** later steps.

## Conflict rules (per entity)

* Assignments, forms, products, review decisions: the server is authoritative. The app keeps unsent local work on a
  changed assignment and tells the user.
* A submitted visit is immutable except for review state, which only the server changes.
* Draft answers: local edits win until they are sent; a download never overwrites a visit with unsent changes.


## Testing on the Android Studio emulator (before a real phone)
1. Android Studio -> **Device Manager** -> *Create device* -> e.g. Pixel 7 with a **Google APIs** system image -> start it.
2. `cd mobile`, run `tools\setup-android.ps1` once, then `flutter run` (or open `mobile/` in Android Studio with the Flutter plugin and press Run).
3. The emulator reaches your PC at `http://10.0.2.2:8080/api/v1` (the app default), so the Docker stack must be up.
4. Fake GPS: emulator **... (Extended controls) -> Location**, pick a point, *Set location* (use the demo outlet coordinates). Accuracy
   is reported as ~5 m, which satisfies the outlet-mapping rule.
5. Offline test: Extended controls -> Cellular/Wi-Fi off, or the quick-settings airplane mode; do work, turn it back on and watch Sync.
6. The camera shows a virtual scene. Real GPS drift, camera quality and mock-location detection still need a real phone.

## Outlets without coordinates / adding outlets
See [OUTLETS.md](OUTLETS.md). On the phone: **My work -> Add outlet**, then *Start outlet & save its location* on the visit screen.
