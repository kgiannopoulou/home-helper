# Deploying Home Helper for €0 a month

One small server runs everything. Android gets an APK, and iPhone and computers get the installable web app (PWA). Every device signs in to the same household server.

```
 Android (APK) ─┐
 iPhone (PWA) ──┼──► https://APP_DOMAIN  (PWA files)   ┐
 Browser ───────┘    https://API_DOMAIN  (API + dashboard) ├─ one Oracle Always Free VM
                                                            │   web (FrankenPHP/Caddy, HTTPS)
                                                            │   queue · scheduler
                                                            │   MySQL 8.4 · Redis 7
                                                            ┘   nightly backup → Object Storage
```

| Need | Free service | Limit to know about |
|---|---|---|
| Server | Oracle Cloud **Always Free** Ampere A1 (up to 4 OCPU / 24 GB in total; 2 OCPU / 12 GB is plenty here) | ARM capacity varies by region. Oracle may **reclaim idle Always Free VMs** (low CPU, network and memory use over 7 days). Keep off-server backups |
| HTTPS | Let's Encrypt through Caddy (built into the image) | Ports 80 and 443 must be reachable |
| Domain names | [DuckDNS](https://www.duckdns.org): two subdomains | Free subdomain, not your own domain |
| Backups | Oracle Object Storage, S3-compatible (Always Free 20 GB) | `db:backup` runs nightly at 03:00 and a restore test runs on Sundays |
| Invite emails | Brevo free SMTP (300/day) or a Gmail app password | |
| Push notifications | Expo push service | Needs the APK, not Expo Go |
| Android app | EAS Build free plan (a limited number of Android builds a month) | Or build the APK locally (below) |
| iPhone app | PWA, so no Apple Developer membership needed | iOS web push only works once the PWA is added to the Home Screen |
| AI | **None.** `AI_PROVIDER=offline`, `EXPO_PUBLIC_ENABLE_AI=0` | The AI extras stay in the code and can be turned back on later |

> **Avoid surprise charges.** Create only resources marked *Always Free eligible* (VM shape `VM.Standard.A1.Flex`, a boot volume of 200 GB or less, Object Storage). Set a **budget alert of €1** under *Billing → Budgets*. As long as the account stays on the Free Tier (you don't upgrade to Pay As You Go), Oracle can't bill you.

## 1. Create the VM (Oracle console, about 10 minutes)

1. Sign up at **cloud.oracle.com** (a card is needed to verify your identity, and nothing is charged). Choose a home region close to you, such as Frankfurt. The home region can't be changed later.
2. **Compute → Instances → Create instance**
   - Image: **Canonical Ubuntu 24.04**
   - Shape: **Ampere → VM.Standard.A1.Flex**, 2 OCPU, 12 GB memory ("Always Free eligible" must show)
   - Networking: create a new VCN with a public subnet, and **assign a public IPv4 address**
   - SSH keys: upload your public key, or download the generated one
   - If it says "Out of capacity", try another availability domain or try again later.
3. **Open ports 80 and 443 in the cloud firewall:** Instance → Subnet → Default Security List → *Add Ingress Rules*: source `0.0.0.0/0`, TCP, destination port `80,443`.
4. Write down the instance's **public IP**.

## 2. Domain names (DuckDNS, 2 minutes)

Sign in at duckdns.org and create two subdomains, for example `myhome` and `myhome-api`. Point both at the VM's public IP.

## 3. Prepare the VM

```bash
ssh ubuntu@<public-ip>
git clone https://github.com/<you>/home-helper.git
cd home-helper
bash deploy/oracle-bootstrap.sh     # Docker, firewall 80/443, swap, automatic updates
exit                                # log in again so the docker group applies
```

## 4. Settings

```bash
cd home-helper
cp deploy/.env.example deploy/.env
nano deploy/.env
```

Fill in the blanks. Each one is explained in the file:

- `API_DOMAIN` and `APP_DOMAIN`
- `DB_PASSWORD`, `DB_ROOT_PASSWORD` and `REDIS_PASSWORD`: create each one with `openssl rand -base64 32`
- `APP_KEY`: after the first build, run `docker run --rm home-helper:latest php artisan key:generate --show`. **Keep it safe.** It decrypts the two-factor secrets.
- The mail settings, `MAIL_FROM_ADDRESS` and `OPS_ADMIN_EMAILS`
- Backups: create a bucket under *Storage → Buckets* (named `home-helper-backups`, Standard tier). Create keys under *Profile → Customer secret keys*. The endpoint is `https://<namespace>.compat.objectstorage.<region>.oraclecloud.com`. You can find the namespace under *Tenancy details*.

## 5. Start it

```bash
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --build
docker compose -f deploy/compose.yaml logs -f web      # wait for app:production-check, then the certificates
```

The first build takes about 10 to 15 minutes on the ARM VM. Then:

- `https://API_DOMAIN/api/health` should answer `{"status":"ok",…}`
- `https://API_DOMAIN` shows the dashboard. **Register your own account**, confirm the email, and start the household under *Household*.
- `https://APP_DOMAIN` is the app.

Never seed demo data in production. `DemoSeeder` refuses to run, and `app:production-check` fails while any demo login exists.

## 6. Install the app

**iPhone:** open `https://APP_DOMAIN` in **Safari**, then tap *Share → Add to Home Screen*. Open it from the icon, go to ⚙️ → *Account & sync*, and sign in. The server address is already filled in.

**Android:** install the APK. You can make it in either of two ways:

```bash
cd mobile
echo "EXPO_PUBLIC_API_URL=https://API_DOMAIN" > .env
npx eas-cli@latest login            # free Expo account
npx eas-cli@latest init             # links the project (adds the projectId push needs)
npm run build:apk                   # cloud build on the free plan, gives a download link/QR
```

Or build it locally with Android Studio installed: `npx expo run:android --variant release`. Send the `.apk` to the family. Each phone has to allow *Install unknown apps* for the browser or Files app once.

Android phones can also just use the PWA from Chrome (*Install app*).

## 7. Invite the household

Go to *Dashboard → Household → Invites* (or ⚙️ → *Household* on the phone) and enter their email. They create an account with that address, then open the link. Then:

- **Someone moves out:** *Household → Remove*. Their phones stop syncing at once, and their unused invite links are deleted.
- **Handing over:** *Make owner*. The last owner can't leave until someone else is owner.
- **Lost phone:** *Settings → Security → Signed-in phones → Sign out all phones*.
- **Two-factor:** *Settings → Security*. The phone app then asks for the code at sign-in.

## Updating

```bash
cd home-helper && git pull
docker compose -f deploy/compose.yaml --env-file deploy/.env up -d --build
```

Migrations run when the web container starts, and the workers pick up the new code. Phones get the new PWA on their next open.

## Backups and restore

`db:backup` runs every night (03:00 Athens time) and keeps 14 copies in the bucket. `db:restore-test` restores the newest copy into a scratch database every Sunday. If anything fails, an alert goes to `OPS_ALERT_EMAIL`. You can also back up by hand:

```bash
docker compose -f deploy/compose.yaml exec web php artisan db:backup
```

If Oracle ever reclaims the VM, create a new one (steps 1 to 5) and restore the newest backup ([server/docs/backups.md](../server/docs/backups.md)).

## Why not Render, Railway or Fly free tiers?

Render's free Postgres expires after 30 days and its free web services sleep when idle, which breaks the scheduled jobs (bills at 06:00, shopping list at 17:00). Other free tiers either need a card with pay-as-you-go billing or don't include a database and a queue worker. Of the free options, the Oracle VM is the only one where the whole stack can run unchanged.

## What has been tested

The whole stack was run locally (x86, Docker Desktop) on 2026-11-25 with `api.localhost` / `app.localhost` and Caddy's local certificates:

- `GET /api/health` returned 200 with database, Redis, queues and scheduler `ok`
- the dashboard login page, the PWA, its manifest and service worker, and deep links (`/kitchen`) were all served over HTTPS
- the PWA bundle has the API address built in, and a CORS preflight from the PWA origin was allowed
- phone login, creating a household, `GET /api/sessions`, and refusing to remove the last owner (422) all worked
- `db:backup` and `db:restore-test` passed inside the image, and the MySQL grant script was checked on a fresh volume
- `app:production-check` ran on start (it failed only on the deliberately fake mail/cookie test settings)

Not tested yet: a real Oracle ARM VM, real DuckDNS names with Let's Encrypt, real SMTP, Object Storage backups, and an EAS-built APK. Those need your accounts.
