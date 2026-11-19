# Putting MetaIntel on a real server

Plain steps, in order. `YOUR_IP` is the server's address and `metaintel.ourcompany.com` is your chosen subdomain; use your own.

## 0. What you need
- A company card for the server account (a prepaid or virtual card works for most providers).
- Access to the company's Namecheap account (or the person who has it).
- The GitHub repository (`Tim-Karani/Meta-Intel-Field-Ops-System`) with this update merged into `main`.
- Your R2 keys (`backend\.env` on your laptop).

## 1. Choose and create the server
- **Size:** 2 CPU and 4 GB memory is enough for the pilot (8 GB gives room to grow). **Operating system: Ubuntu 24.04 LTS.** Turn on the provider's **automatic backups** (about 20% extra).
- **Where:** a data centre in Europe is the usual cheap choice (about 150 ms from Nairobi, fine for this app). Johannesburg or Cape Town regions are nearer but cost more.
- **Provider:** Hetzner is the cheapest good choice. DigitalOcean, Vultr or Linode work identically if sign-up is a problem.
- **Login method:** choose an **SSH key**, not a password (step 3).

## 2. Point the address at the server (Namecheap)
1. Namecheap > **Domain List** > **Manage** next to the domain > **Advanced DNS**. (If you do not see Advanced DNS the domain uses other nameservers; ask whoever set that up.)
2. **Add New Record**: Type **A Record**, Host `metaintel`, Value `YOUR_IP`, TTL Automatic. Save. Change nothing else.
3. Wait 5 to 30 minutes, then check on your laptop: `nslookup metaintel.ourcompany.com` must show `YOUR_IP`.

## 3. Make your key and connect (from your laptop, PowerShell)
```powershell
ssh-keygen -t ed25519 -C "metaintel-server"
Get-Content $env:USERPROFILE\.ssh\id_ed25519.pub
```
Paste the line printed (the PUBLIC key, ends `.pub`) into the provider's "SSH key" box when creating the server. Never share the other file. Then:
```powershell
ssh root@YOUR_IP
```
Answer `yes` once. You are now typing on the server.

## 4. Make the server safe (on the server)
```bash
apt update && apt -y upgrade
adduser deploy                       # choose a strong password
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh && cp /root/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
apt -y install fail2ban unattended-upgrades
timedatectl set-timezone Africa/Nairobi
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy
```
Open a second PowerShell and check `ssh deploy@YOUR_IP` works. Only then, in the first window, run
`sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/;s/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && systemctl restart ssh`
and from now on always log in as `deploy`.

## 5. Get the code (as deploy)
The repository is private, so give the server a read-only key:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
```
GitHub > the repo > **Settings > Deploy keys > Add deploy key** (leave "write access" off) and paste it. Then:
```bash
git config --global core.sshCommand "ssh -i ~/.ssh/github_deploy"
git clone git@github.com:Tim-Karani/Meta-Intel-Field-Ops-System.git field-ops
cd field-ops
```

## 6. Settings
```bash
cp .env.server.example .env && nano .env          # APP_DOMAIN and a long DB_PASSWORD (letters and numbers only)
cp backend/.env.example backend/.env && nano backend/.env
```
In `backend/.env` set (everything else can stay):
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://metaintel.ourcompany.com
WEB_URL=https://metaintel.ourcompany.com
CORS_ALLOWED_ORIGINS=https://metaintel.ourcompany.com
LOG_LEVEL=warning
TRUSTED_PROXIES=*
DB_PASSWORD=<same as in the root .env>
EVIDENCE_DISK=s3
AWS_ACCESS_KEY_ID= / AWS_SECRET_ACCESS_KEY= / AWS_BUCKET= / AWS_ENDPOINT=   (from your laptop's .env)
ADMIN_NAME=...  ADMIN_EMAIL=...  ADMIN_PASSWORD=<10+ characters, you change it at first sign-in>
```
Save in nano with Ctrl+O, Enter, Ctrl+X. Prefer a **new R2 bucket** for the live project so test photos stay separate.

## 7. Start it
```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show      # copy the "base64:..." line into APP_KEY in backend/.env
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose ps
```
Open `https://metaintel.ourcompany.com`. The first load can take a minute while the padlock is issued. Sign in with the ADMIN details, change the password, then check **System health** is all green. Switch the laptop tunnel off.

## 8. Backups
```bash
mkdir -p ~/field-ops/backups && ~/field-ops/scripts/backup.sh
crontab -e        # add the line:  0 2 * * * /home/deploy/field-ops/scripts/backup.sh >> /home/deploy/backup.log 2>&1
```
Also keep the provider's automatic backups on. **Practise a restore once** before the pilot.
Restore: `docker compose exec -T db pg_restore -U field_ops -d field_ops -c < backups/FILE.dump`.

## 9. Phones
Server settings: `https://metaintel.ourcompany.com/api/v1`. No new APK is needed.

## 10. Updating later
```bash
cd ~/field-ops && git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose restart app queue scheduler
```

## 11. Watchman (free)
Add `https://metaintel.ourcompany.com/api/v1/health` to a free uptime service (for example UptimeRobot) with your email as the alert.

## Moving your laptop data instead of starting fresh (optional)
Laptop: `docker compose exec db pg_dump -U field_ops -Fc field_ops > laptop.dump`. Copy it to the server (`scp laptop.dump deploy@YOUR_IP:`), start the stack (step 7 without `db:seed`), then restore as in step 8. Pilot data is usually better started clean.
