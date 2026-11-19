# Outlet pilot test: checklist

Use this for the first real-outlet test through the Cloudflare tunnel. Keep the results private.

## Before the day (on the laptop)
- [ ] `backend/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `TRUSTED_PROXIES=*` (so each tester is counted separately for sign-in limits), `EVIDENCE_DISK=s3`.
- [ ] `docker compose up -d --force-recreate app queue scheduler`, then System health is all green (including Evidence storage).
- [ ] Administrator password is strong; every test account has its own password.
- [ ] Laptop: plugged in, sleep turned off, Docker Desktop running, the tunnel window open.
- [ ] Database backup taken: `docker compose exec db pg_dump -U field_ops field_ops > backup_YYYYMMDD.sql`.
- [ ] Tunnel address copied; on every test phone: Server settings = `https://<address>/api/v1`; sign-in works on mobile data.
- [ ] Test project, outlets, products, a journey plan for today and the test agents are set up.

## On the day, per agent
1. Sign in; allow Location, Camera and Notifications.
2. Home shows today's Next stop.
3. Check in at the outlet (inside the geofence). Try once outside it; it should be flagged or refused as designed.
4. Do the visit: required photos, answers, products.
5. Submit with a connection. Then repeat once in airplane mode and reconnect; it must sync by itself.
6. Request a journey-plan change from More > Journey plan change.
7. Report an issue from Quick actions.
8. Check out; Attendance shows the day.

## What to confirm on the web
- [ ] The visit and its photos appear (photos open, so R2 works).
- [ ] Dashboard: Team right now shows the agent; Recent activity shows the visit.
- [ ] Field agents / Live operations shows position and battery.
- [ ] The plan-change request reaches the supervisor; approve it and see it on the phone.
- [ ] Reports and exports match what the agents did.

## Record for each problem
Time, agent, phone model, what you did, what you saw (screenshot), and whether it was offline or online.

## After the day
- [ ] Back up the database again.
- [ ] Note what was slow, confusing or missing, and send the list.
