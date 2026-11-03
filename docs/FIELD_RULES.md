# Field rules (v1.3): attendance, check-in, photos, GPS, reports

How the platform proves that the assigned agent really worked the outlet, and how time worked is measured.

## 1. Clock-in is not time worked

* **Clock in / out** (`/attendance/clock-in`) only records that the agent's day started. An agent can do it from home.
* **Working time starts at the first outlet check-in of the day** and ends at the last check-out. The server writes it onto the day's attendance record:
  `work_started_at`, `work_ended_at`, `worked_minutes` (first check-in → last check-out) and `outlets_visited`.
* If an agent never clocked in, the first outlet check-in still creates the attendance record.
* **Late** is judged on `work_started_at` against the earliest scheduled start time of the day's assignments (+15 min), not on the clock-in.
* The *Attendance* report shows: days worked (outlet check-in), late starts, **clocked in with no outlet**, outlets visited, absent, average hours worked.
* Times use the server's clock (arrival time), so a phone clock cannot move them.

## 2. Check-in rules (enforced by the server, mirrored on the phone)

| Rule | What happens |
|---|---|
| More than **200 m** from a mapped outlet (allowing up to 30 m credit for a noisy fix) | check-in refused, code `outside_radius`; recorded as a `checkin_blocked_distance` event and in the audit log |
| **Fake / mock GPS** reported by the phone | check-in refused, code `fake_gps`; recorded (`fake_gps_blocked`), audited, and the agent's Team Leader, Supervisor and campaign managers are notified (`visit.fake_gps`) |
| Outlet has no saved position yet | the check-in maps the outlet (needs 50 m accuracy or better); distance cannot be checked, mock GPS is still refused |
| Other GPS signals (poor accuracy, clock skew, impossible travel, changed device...) | scored as before: `valid` / `warning` / `suspicious` / `rejected`, for reviewers |

The limit is `config/field.php → checkin.max_distance_m` (env `CHECKIN_MAX_DISTANCE_M`).
Check-out never blocks an agent from leaving the store.

> GPS spoofing detection is evidence, not a guarantee: the phone reports whether a mock-location provider is in use, and the server cross-checks accuracy, distance, clock and travel speed. Treat repeated `fake_gps` events as a conduct matter.

## 3. Check-in and check-out photos

* To check in, the agent takes a **selfie at the outlet** (camera only, no gallery); to close the outlet they take a **check-out photo**. Both are stamped with date/time, GPS, outlet and agent, and the stamp starts with `CHECK-IN` / `CHECK-OUT`.
* They are uploaded as photo kinds `checkin` and `checkout` and require `source=camera`.
* **A visit cannot be submitted without both** (error keys `checkin_photo`, `checkout_photo`). A project can switch this off: *Agent must take a check-in photo and a check-out photo* in the project setup (`settings.visit_photos`).
* The phone refuses to check in without the photo, because that photo is what proves the assigned agent is the one servicing the outlet.
* Reviewers see them in *Team attendance* and on the visit's Photos tab (labelled Check-in / Check-out). Agents cannot delete them.

## 4. Team attendance (web)

*Operations → Team attendance* (`GET /attendance/board?date=`): for each person in the viewer's scope, whether they are **at an outlet**, **worked**, **clocked in but not at an outlet**, or **not started**; clock-in time, work start, last check-out, time worked, outlets, current outlet. Below it, every outlet check-in of the day with distance from the outlet, GPS result and buttons to view both proof photos (missing ones are flagged).

## 5. Reports: on demand and scheduled

* New reports: **Outlet check-ins** (`checkin`) and **Share of shelf** (`sos`). Share of shelf = *our facings ÷ all facings counted*; agents enter both numbers in the Merchandising record.
* **On demand:** open any report → filters → *Export CSV* (opens in Excel).
* **Scheduled:** *Insights → Scheduled reports*. Choose the report, what period it covers (yesterday, last 7 days, month to date...), every day / week / month, the time, optional campaign/client filter and the email addresses. Each address gets its own email with the CSV attached. *Send now* tests it immediately.
* A schedule is built with **its owner's access** (a Supervisor's schedule only contains the Supervisor's scope). If the owner is disabled or loses report access the schedule pauses.
* The `scheduler` container runs `reports:send-due` every 5 minutes; each schedule is sent once a day at/after its time. **Email must be configured** (see DEPLOYMENT.md), otherwise emails are only written to the log.
