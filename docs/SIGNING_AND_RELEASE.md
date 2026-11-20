# Signing key and releasing the phone app

## Why a signing key
Every Android app is stamped with a private key. A phone only accepts an update if it carries the **same stamp** as the installed app. Lose the key and you can never update the app on your agents' phones: they would have to uninstall and reinstall, and anything not yet synced would be lost. So: make it once, back it up in several places, and never put it in GitHub.

## 1. Make the key (once, on your laptop)
`keytool` ships with Android Studio's Java. In PowerShell:
```powershell
mkdir D:\MetaIntelKeys
& "C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe" -genkey -v -keystore D:\MetaIntelKeys\metaintel-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias metaintel
```
(If that path does not exist, run `flutter doctor -v`; it prints the Java location, then use its `bin\keytool.exe`.) It asks for a password twice (use a long one) and some details (company name, city, country `KE`). Write the password down in the company password manager at once.

## 2. Tell the build about it
Create `mobile\android\key.properties` (this file is ignored by Git; never commit it):
```
storePassword=THE_PASSWORD
keyPassword=THE_PASSWORD
keyAlias=metaintel
storeFile=D:/MetaIntelKeys/metaintel-release.jks
```
On a second laptop, copy both files and fix `storeFile` to that laptop's path.

## 3. Back it up (at least two places, today)
1. The company password manager (the password) and a private company drive folder with restricted access (the `.jks` file).
2. An encrypted USB stick kept by a second named person, plus the boss's sealed copy of the password.
Test the backup: copy it to another folder and confirm the file opens (the file size is a few KB).

## 4. Build the signed app
```powershell
cd D:\Projects\Meta_Intel_Technologies\mobile
flutter pub get
flutter test
flutter build apk --release
```
The file is `mobile\build\app\outputs\flutter-apk\app-release.apk`. Without `key.properties` the build still works but is signed with a throw-away test key; such a file must never go to agents.
**The first properly signed app has a different stamp from your test APK, so uninstall the old test app first.** After that, every update installs over the top.
Raise the number after `+` in `pubspec.yaml` (`version: 1.11.0+4`) for every release you give to phones, or Android refuses to install it as an update.

## 5. Getting it onto agents' phones
**A. Send the file (free, fine for the pilot).** Put `app-release.apk` on a private link (company drive, restricted) or send it directly. On the phone: open the file, allow "Install unknown apps" for that one app (Chrome, Files, WhatsApp) when asked, install. Each update is a new file with a higher version number.
**B. Google Play, private testing (about USD 25 once; recommended for the real rollout).** Easier updates (automatic), no "unknown apps" setting, and Google has announced stricter checks on apps installed from outside its store.
1. Create a Google Play Console developer account in the company's name (one-time fee; an organisation account needs the company's verification details; check Google's current rules for new accounts, which may require a test period with a number of testers before public release).
2. Create the app, then use an **Internal testing** (up to 100 testers, by email address) or **Closed testing** track, so it is never public.
3. Build the Play file instead: `flutter build appbundle --release`, upload `app-release.aab` (Play offers to manage the final signing key for you, which also protects you if your upload key is ever lost).
4. Add each agent's Google account email as a tester; they install from a private link.

## Checklist before every release to phones
- [ ] `flutter test` passes.
- [ ] Version number after `+` is higher than the last release.
- [ ] Built with `key.properties` in place (not the throw-away key).
- [ ] Tried on one real phone: sign in, one visit with photo, scan, airplane-mode sync.

## When the server and domain are ready: build with the address inside the app
Do this only **after the server and address exist**, using your real subdomain:
```powershell
cd D:\Projects\Meta_Intel_Technologies\mobile
flutter build apk --release --dart-define=API_BASE_URL=https://metaintel.ourcompany.com/api/v1
flutter build appbundle --release --dart-define=API_BASE_URL=https://metaintel.ourcompany.com/api/v1   # for Google Play
```
- The built-in address is only a **starting point**. An agent can still open **Server settings** on the sign-in screen and change it, which helps for testing or moving servers. A saved address on a phone overrides the built-in one.
- **Test phones may still have the old tunnel address saved.** Uninstall the old test app (you must anyway for the first signed build); they then pick up the new default.
- If the address ever changes, phones with the old build need the manual setting or a new build.
- The same flag works for the plain APK you send by file.

## Reminder for when the server and domain are being connected
Email for reset links (full steps in docs/EMAIL_SETUP.md):
1. Create a free Brevo account (300 emails a day) in the company's name.
2. Verify the sending domain: add Brevo's DNS records in Namecheap > Advanced DNS.
3. Create an SMTP key in Brevo.
4. In backend/.env on the server set MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT=587, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS=noreply@yourdomain, MAIL_FROM_NAME="MetaIntel", WEB_URL=https://your-real-address
5. docker compose up -d --force-recreate app queue scheduler
6. System health > Send test email.
Until then "Reset password now" on the Users page works without email.
