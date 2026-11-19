# Real email (password resets, scheduled reports)

Until this is done the system only writes emails into the server log (`MAIL_MAILER=log`). Nothing reaches an inbox.

## 1. Pick a sender service (free to start)
Brevo's free plan allows 300 emails a day, which is plenty for the pilot. Any SMTP provider works the same way.
1. Create the account in the company's name.
2. **Verify the sender domain** (strongly advised, or messages land in spam): the service shows a few DNS records (SPF, DKIM). Add them in Namecheap > Advanced DNS, exactly as shown, then press Verify. Allow up to a few hours.
3. Create an **SMTP key** (the service's "SMTP & API" page shows the host, port, login and where to make the key).

## 2. Put it in `backend/.env` (on the server)
```
MAIL_MAILER=smtp
MAIL_HOST=<host shown by the service>
MAIL_PORT=587
MAIL_USERNAME=<SMTP login>
MAIL_PASSWORD=<SMTP key>
MAIL_FROM_ADDRESS=noreply@ourcompany.com
MAIL_FROM_NAME="MetaIntel"
```
Port 587 uses a secure connection automatically. If the service tells you to use port 465, set `MAIL_PORT=465` and `MAIL_SCHEME=smtps`. (Older notes mention `MAIL_ENCRYPTION`; current Laravel ignores it.)
`MAIL_FROM_ADDRESS` must be an address on the verified domain.

## 3. Restart and test
```bash
docker compose up -d --force-recreate app queue scheduler
```
Then open **System health** on the web, press **Send test email**, and check the inbox (and the spam folder). The page also says plainly when email is still set to `log`.

## 4. Check the real flow
Sign out, use **Forgot your password?** with a test account, and open the link from the email. The link uses `WEB_URL`, so `WEB_URL` must be the real address (`https://metaintel.ourcompany.com`).

## Troubleshooting
- "The email could not be sent": wrong host, login or key, or the sender domain is not verified. The exact error is in `docker compose logs app`.
- Arrives in spam: the SPF/DKIM records are missing or not verified yet.
- Over the daily limit: the free plan stops at 300 a day.
