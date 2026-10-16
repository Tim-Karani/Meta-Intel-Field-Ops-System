# Outlets: mapped, unmapped and field-added

An outlet (`locations` row) no longer needs coordinates up front.

| Case | How it gets a position | `geo_source` | Reviewed? |
|---|---|---|---|
| Office enters latitude + longitude | Entered on the web form | `manual` | not needed |
| Office adds the outlet **without** coordinates | The agent's first valid check-in there | `first_visit` | flagged `needs_review` |
| Agent adds the outlet in the field (mobile "Add outlet") | The agent's check-in right after adding it | `field_added` | flagged `needs_review` |

## Rules (enforced by the server, `OutletService` + `VisitService::checkIn`)
- Latitude and longitude are both set or both empty.
- Mapping an outlet from a check-in needs a clean fix: no mock-location flag, GPS accuracy reported and
  <= `field.gps.capture_max_accuracy_m` (50 m). A worse fix is rejected with a message to try again in the open.
  The mobile app applies the same check **before** queueing the check-in so an offline agent is not left with a rejected step.
- For an unmapped outlet the geofence distance is 0 (the reading is the position); the other GPS signals
  (clock skew, impossible travel, device change) still apply.
- The position is written once, under a row lock. If two agents check in at the same time, the first one maps it and the
  second is judged against that position.
- A reviewer (`locations.manage`) opens the outlet on the web, checks the pin and presses **Confirm position**
  (`POST /locations/{id}/confirm-geo`), optionally sending corrected coordinates. Editing coordinates by hand also clears the flag.
- Web list: filter **Position** = Not mapped yet / Needs confirmation / Mapped.

## Field-added outlets
`POST /locations/field` (permission `locations.capture`, granted to Field Agents and System Admin):
`location_id` and `assignment_id` are UUIDs generated on the phone, so the call is idempotent and the visit can reference
them before anything has synced. The server creates the outlet, links it to the campaign, and gives the agent an
**accepted** assignment for today (form chosen as for office-created assignments). Supervisors and campaign managers are notified.
A second outlet with the same name (and town) in the campaign is refused with `409 duplicate_outlet`.

## Mobile outbox order
`outlet_create` -> `visit_create` -> `visit_checkin` -> ... all share the visit's entity id, so if adding the outlet is
stuck, the later steps wait behind it and other visits keep syncing.
