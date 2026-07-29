# Apple & Google Wallet passes

Adds "Add to Apple Wallet" / "Add to Google Wallet" buttons to the public ticket page
(`/product/{eventId}/{attendeeShortId}`). The pass barcode encodes the attendee `public_id`, so the
existing check-in scanner works unchanged.

Each button only appears once its platform is configured (the public event API exposes
`apple_wallet_enabled` / `google_wallet_enabled`, sourced from the config below). With no
credentials set, both buttons stay hidden and the endpoints return 404.

## Endpoints

- `GET /public/events/{event_id}/attendees/{attendee_short_id}/apple-wallet-pass` — streams a signed
  `.pkpass` (`application/vnd.apple.pkpass`).
- `GET /public/events/{event_id}/attendees/{attendee_short_id}/google-wallet-pass` — returns
  `{ "save_url": "https://pay.google.com/gp/v/save/…" }`.

Passes are generated with PHP built-ins (`openssl` + `ZipArchive`) — no extra Composer packages.

## Apple Wallet setup — step by step

**Prerequisite:** a paid **Apple Developer Program** membership (99 USD/year). The whole flow can be
done from any OS using OpenSSL — a Mac/Keychain is *not* required.

You need to produce three things: the **Pass Type ID**, a **`.p12`** (your signing certificate +
its private key), and the **WWDR** intermediate certificate as PEM.

### 1. Find your Team ID
Sign in at <https://developer.apple.com/account> → **Membership details**. Copy the 10-character
**Team ID** (e.g. `ABCDE12345`) → this is `APPLE_WALLET_TEAM_IDENTIFIER`.

### 2. Register a Pass Type ID
1. Go to **Certificates, Identifiers & Profiles → Identifiers**
   (<https://developer.apple.com/account/resources/identifiers/list>).
2. Click **+**, choose **Pass Type IDs**, continue.
3. Enter a description and an identifier of the form `pass.com.yourorg.tickets`
   (must start with `pass.`). Register it.
   → this identifier is `APPLE_WALLET_PASS_TYPE_IDENTIFIER`.

### 3. Create the signing certificate (OpenSSL route)
1. Generate a private key and a Certificate Signing Request (CSR):
   ```bash
   openssl genrsa -out pass.key 2048
   openssl req -new -key pass.key -out pass.certSigningRequest \
     -subj "/emailAddress=you@yourorg.com/CN=Hi.Events Pass/C=DE"
   ```
2. In the portal, open your Pass Type ID → **Create Certificate** → upload `pass.certSigningRequest`
   → **Download** the resulting `pass.cer`.
3. Convert the downloaded certificate to PEM and bundle it with your key into a password-protected
   `.p12`:
   ```bash
   openssl x509 -inform der -in pass.cer -out pass.pem
   openssl pkcs12 -export -out pass.p12 -inkey pass.key -in pass.pem
   # you will be prompted for an export password — remember it
   ```
   → `pass.p12` is `APPLE_WALLET_CERTIFICATE_PATH`, the export password is
   `APPLE_WALLET_CERTIFICATE_PASSWORD`.

### 4. Get the Apple WWDR intermediate certificate
Download **Worldwide Developer Relations – G4** from
<https://www.apple.com/certificateauthority/> (`AppleWWDRCAG4.cer`) and convert to PEM:
```bash
openssl x509 -inform der -in AppleWWDRCAG4.cer -out wwdr.pem
```
→ `wwdr.pem` is `APPLE_WALLET_WWDR_CERTIFICATE_PATH`.
(This certificate is what makes the phone trust the pass signature.)

### 5. Set the env vars
```
APPLE_WALLET_PASS_TYPE_IDENTIFIER=pass.com.yourorg.tickets
APPLE_WALLET_TEAM_IDENTIFIER=ABCDE12345
APPLE_WALLET_ORGANIZATION_NAME="Your Organisation"
APPLE_WALLET_CERTIFICATE_PATH=/absolute/path/to/pass.p12
APPLE_WALLET_CERTIFICATE_PASSWORD=your-p12-export-password
APPLE_WALLET_WWDR_CERTIFICATE_PATH=/absolute/path/to/wwdr.pem
```
Store the `.p12` / `.pem` outside the repo and mount them into the backend container (e.g. under
`storage/` or a dedicated secrets volume). Keep the private key secret.

The pass icon/logo is taken from the event's `TICKET_LOGO` image (falling back to `EVENT_COVER`).
Events with neither image cannot generate an Apple pass. Non-PNG images are converted with Imagick
when available; otherwise a PNG logo is required.

## Google Wallet setup — step by step

**Prerequisite:** a Google account + a Google Cloud project (free to create). No billing needed for
the Wallet API.

You need two things: the **Issuer ID** and a **service account JSON key** with Wallet issuer access.

### 1. Enable the Google Wallet API
In the Google Cloud console (<https://console.cloud.google.com>), select/create a project, then go
to **APIs & Services → Library**, search **Google Wallet API**, and click **Enable**.

### 2. Get an Issuer ID
1. Open the **Google Wallet API / Business Console**:
   <https://pay.google.com/business/console>.
2. Complete the issuer sign-up (organisation name, contact). This gives you an **Issuer ID** — a
   long number shown at the top of the console (e.g. `3388000000012345678`).
   → this is `GOOGLE_WALLET_ISSUER_ID`.

### 3. Create a service account + key
1. Cloud console → **APIs & Services → Credentials → Create credentials → Service account**.
2. Give it a name, create it (no roles required for the skinny-JWT flow).
3. Open the service account → **Keys → Add key → Create new key → JSON** → a `.json` file
   downloads. This file contains `client_email` and `private_key`.
   → this file is `GOOGLE_WALLET_SERVICE_ACCOUNT_JSON`.

### 4. Authorise the service account in the Wallet console
Back in the **Google Wallet Business Console** (<https://pay.google.com/business/console>) →
**Users / Account management**, add the service account's `client_email` as a member with
**Developer** (Wallet Object Issuer) access. Without this the passes won't be accepted.

### 5. Set the env vars
```
GOOGLE_WALLET_ISSUER_ID=3388000000012345678
GOOGLE_WALLET_SERVICE_ACCOUNT_JSON=/absolute/path/to/service-account.json
GOOGLE_WALLET_CLASS_SUFFIX=hievents_event_ticket
GOOGLE_WALLET_ORIGIN=https://your-frontend-url
```
`GOOGLE_WALLET_SERVICE_ACCOUNT_JSON` may be an absolute path to the file **or** the raw JSON string
(handy for secret managers). `GOOGLE_WALLET_ORIGIN` should be your public frontend URL — Google ties
the "Save" link to this origin. The event ticket class and object are embedded directly in the
signed "skinny" JWT, so no server-to-server API calls are needed to issue a pass.
