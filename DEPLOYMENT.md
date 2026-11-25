# InsQuire deployment checklist

InsQuire has two separate web origins: Personal at `insquire.org` and
Education at `ed.insquire.org`. They share application code and a MySQL
database, but use different account scopes and session-cookie names. A user
must have a separate account for each site; a Personal account never
authenticates as an Education account, or vice versa.

## Angular frontend

The Angular 21 + Tailwind frontend source is in `frontend/`, outside the web
document root. Install the locked dependencies and build the production
bundle:

```sh
cd frontend
npm ci
npm run build:deploy
```

The build writes the static application to `public/app/`. `push.sh` performs
this production build automatically before syncing, installing dependencies
from the lockfile if needed. Apache must allow
the supplied `public/.htaccess` rules and have `mod_rewrite`, `mod_headers`,
and `mod_setenvif` enabled. The Angular home, sign-in, registration, dashboard, and class-list
screens use the same-origin endpoints under `public/api/v1/`. Other legacy
feature pages remain available until each has an equivalent Angular screen
and API; do not remove or redirect a PHP feature endpoint before its
replacement is tested.

## Before release

- [ ] Deploy each virtual host with its document root set to its own
  `public_html/public` directory. Do not expose `config/`, `includes/`,
  `database/`, `storage/`, private credentials, or this deployment package
  through the web server.
- [ ] Enable Apache `mod_rewrite` and `mod_headers`, then verify the `.htaccess` rules serve the
  host-specific `robots.txt` and `sitemap.xml` from their PHP endpoints.
- [ ] Build the Angular frontend from its locked dependencies and deploy the
  resulting `public/app/` files alongside the API.
- [ ] Configure both hosts to use the same intended MySQL database, with a
  least-privilege application user, UTF-8 (`utf8mb4`), and remote backups.
- [ ] Back up the production database and rehearse the migration sequence on a
  staging copy first.
- [ ] For an existing installation, apply `database/account_separation.sql`
  before deploying the account-scope PHP code. Existing accounts remain
  Education accounts. Do not rerun the cleanup-oriented
  `database/migrations.sql` on production.
- [ ] Apply any missing, prerequisite migrations described in
  `EDITION_FEATURES.md`, `EDUCATION_ADMINISTRATION.md`, and
  `BILLING_SETUP.md` (including edition features, institutions/moderation,
  notifications, and billing, in that order).
- [ ] Configure each host's server-private `private/config.php` outside the
  document root. Set the same `ESSAY_DB_HOST`, `ESSAY_DB_NAME`,
  `ESSAY_DB_USER`, and `ESSAY_DB_PASS` for both hosts, and set the canonical
  `ESSAY_PERSONAL_APP_URL` and `ESSAY_EDUCATION_APP_URL` values. Keep API,
  Stripe, and other secrets out of the repository and web root.
- [ ] Enable HTTPS on both origins and ensure PHP receives the HTTPS request
  state so session cookies are marked `Secure`. Configure Google Identity
  Services with both exact authorized JavaScript origins and set
  `ESSAY_GOOGLE_OAUTH_CLIENT_ID` to the configured OAuth client ID on both
  hosts.
- [ ] Confirm production PHP includes PDO MySQL, cURL, mbstring, and DOM. Confirm
  the web process can write only to the storage directories that require it.
- [ ] If Apache `ErrorDocument` files are served from a separate Hestia
  `document_errors/` directory, include that exact per-domain directory in the
  PHP-FPM pool's `open_basedir` allowlist. The supplied Hestia PHP-FPM template
  for InsQuire includes `/home/%user%/web/%domain%/document_errors`; the
  deployment script installs the pages but does not edit server PHP settings.
- [ ] Set `display_errors=Off` and `expose_php=Off` in production PHP settings;
  send errors to protected server logs.
- [ ] Ask the host to confirm the exact PHP-FPM/Apache PHP runtime used by
  both domains, then update it to the latest patched release on a supported
  PHP branch before launch. The PHP release page currently identifies PHP
  8.5.11, 8.4.26, and 8.3.35 as security releases (September 24, 2026);
  use the newest security release available when deploying:
  [PHP releases](https://www.php.net/releases/).
- [ ] Privately provision and test at least one platform-admin account. Never
  create platform admins or teachers through public role selection. The
  platform `admin` role is distinct from an institution's scoped admin
  membership.
- [ ] Requests to user-facing pages and account flows on `insquire.org` redirect
  to `ed.insquire.org`; `/api/` and the Stripe webhook stay available for
  API clients and billing events. Personal accounts and their data are
  retained but do not authenticate as Education accounts.
- [ ] Configure `ESSAY_EDUCATION_DEFAULT_CLASS_ID` in the Education host's
  private `config.php` to the active class that new student accounts should
  join. With this setting, student registration without a code joins that
  class; a supplied code still selects a different active class.
- [ ] Confirm deployment applied `database/user_calendar_events.sql` and test
  that students can add, view, remove, and export a personal calendar event.
- [ ] For Canvas LTI 1.3, configure the server-private settings and Canvas
  Developer Key described in the Canvas section below. Do not enable Canvas
  launches until both systems are configured for HTTPS.
- [ ] On Education, test default-class registration and verify the new student
  is enrolled in the configured class. Test invalid class codes and teacher
  registration with an active invitation (including an email-targeted
  invitation), invalid/expired/reused invitations, and attempts to select a
  teacher/admin role without an invite.
- [ ] Test an existing account's class join flow, teacher feedback containing
  profanity (save and return must both be rejected), and ordinary feedback
  (must save and return successfully).
- [ ] On a teacher submission page, select a sentence, add a comment, and
  confirm its highlight and comment are visible to the student after returning
  the assignment. Confirm comments remain safe when the submission contains
  formatted text.
- [ ] Keep billing and advertising disabled until the separate test and
  compliance steps in `BILLING_SETUP.md` have passed. Review
  `AI_CONFIGURATION.md` before enabling external AI feedback.

## Account rules

- User-facing requests to `insquire.org` redirect to `ed.insquire.org`;
  `/api/` endpoints and the Stripe webhook remain available. Existing
  Personal accounts/data are retained but do not authenticate as Education
  accounts.
- New Education student accounts join the active class selected by
  `ESSAY_EDUCATION_DEFAULT_CLASS_ID` when no alternate class code is submitted.
  The account and enrollment are created in one database transaction.
- A new Education teacher must use an active institution invitation. Invitation
  links are single-use, expire after seven days, and may be bound to an email.
- Platform-admin accounts are provisioned privately. Public registration
  cannot create an `admin` account.
- Existing Education accounts are retained. Existing account rows are not
  reclassified or split by the account-separation migration.

The application is not live-ready until the operator has completed the
database backup/migration, provisioned secrets and administrators, enabled
HTTPS, and passed the staging and origin-isolation checks above.

## InsQuire application deploy

> **Note:** `push.sh` is the maintainer's private deploy script and is not
> included in this repository, because it contains server-specific hosts.
> Self-hosters can deploy with any rsync/SFTP workflow that follows the steps
> below.

From the workspace root containing `push.sh`, run `./push.sh --check` to
validate the application tree and required migrations locally without
contacting the server. Run `./push.sh` to synchronize all files and
directories under `construction.insquire.org/public_html`, including newly
added files without updating a deployment list. `.DS_Store` files and runtime
`frontend/node_modules/`, Angular caches, `.DS_Store` files, and runtime
`storage/` contents are excluded; the storage access-control file is deployed
separately. The custom PHP error pages are also backed up and synchronized to
the separate error-document directories for both `insquire.org` and
`ed.insquire.org`. The command does not delete unrelated files from the server
and does not deploy anything outside the application tree, including
server-private configuration.

The deployment requires SSH key access as `root` and `rsync` on both ends. It

## Canvas integration setup

InsQuire uses Canvas LTI 1.3 for course launches, student roster access,
assignment selection, and grade/comment passback. Canvas OAuth is used
separately for direct publishing into modules and (optionally) importing native
Canvas assignments. The tool must be configured on the Education host,
`https://ed.insquire.org`; do not point Canvas at the Personal site.

### 1. Prepare the Education host

1. Confirm that `https://ed.insquire.org` and your Canvas site both use HTTPS.
   Canvas will block secure LTI launches if its own origin redirects to HTTP.
2. Apply `database/lti_canvas.sql` and `database/canvas_publishing.sql`. The
   deployment script applies both migrations. The LTI migration allows more
   than one Canvas course deployment to map to a merged InsQuire class.
3. Generate a new RSA private key on a trusted machine. Keep it out of this
   repository, the web document root, email, and support tickets:

   ```sh
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out insquire-lti-private.pem
   chmod 600 insquire-lti-private.pem
   openssl pkey -in insquire-lti-private.pem -text -noout
   ```

   Confirm the key reports at least 2048 bits. Do not hand-edit a public JWK;
   the public key must be derived from this exact private key.
4. In the server-private
   `/home/<user>/web/ed.insquire.org/private/config.php` settings array, set
   the Canvas values. Preserve other existing settings. Use the issuer and
   endpoints from your Canvas instance rather than assuming the issuer is its
   browser hostname:

   ```php
   'ESSAY_LTI_ISSUER' => 'https://canvas-issuer-from-your-instance',
   'ESSAY_LTI_CLIENT_ID' => 'client-id-from-the-developer-key',
   'ESSAY_LTI_DEPLOYMENT_ID' => 'deployment-id-from-the-installed-app',
   'ESSAY_LTI_LOGIN_URL' => 'https://canvas-host/api/lti/authorize_redirect',
   'ESSAY_LTI_TOKEN_URL' => 'https://canvas-host/login/oauth2/token',
   'ESSAY_LTI_JWKS_URL' => 'https://canvas-host/api/lti/security/jwks',
   'ESSAY_LTI_KEY_ID' => 'insquire-canvas-2026',
   'ESSAY_LTI_PRIVATE_KEY' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
   ```

   Replace every example value with the value shown by your Canvas instance.
   `ESSAY_LTI_KEY_ID` is InsQuire's signing-key ID; it must match the `kid`
   served by `https://ed.insquire.org/lti-jwks.php`. It is not the Canvas
   Developer Key's client ID or deployment ID.

### 2. Create and enable the Canvas LTI Developer Key

An account administrator should open Canvas **Admin → Developer Keys** and
create an **LTI 1.3** key (some Canvas versions label this **LTI Key**). In the
key configuration, enter:

| Canvas setting | Value |
| --- | --- |
| OIDC initiation / login URL | `https://ed.insquire.org/lti-login.php` |
| Target Link URI / launch URL | `https://ed.insquire.org/lti-launch.php` |
| Public JWK URL | `https://ed.insquire.org/lti-jwks.php` |
| Course Navigation placement | Enable; this is how teachers and students open InsQuire inside a course |
| Assignment Selection / Deep Linking placement | Enable; this lets teachers select published InsQuire assignments in Canvas |
| Assignment and Grade Services | Enable line-item access and score submission |
| Names and Role Provisioning Services | Enable read-only course roster access |

In **Custom Fields**, add this exact line (or the equivalent JSON key/value):

```text
insquire_canvas_course_id=$Canvas.course.id
```

For a JSON Developer Key configuration, the relevant property is:

```json
"custom_fields": {
  "insquire_canvas_course_id": "$Canvas.course.id"
}
```

Canvas substitutes the numeric API course ID into this signed launch claim.
InsQuire cannot derive that number from LTI `context.id`, which is an opaque
identifier. Saving this field is required for the Publish to Canvas page to
load modules and assignments.

Before enabling the key, verify that
`https://ed.insquire.org/lti-jwks.php` returns a JSON object with a non-empty
`keys` array. Then save and enable the Developer Key and copy its **Client ID**
into `ESSAY_LTI_CLIENT_ID`.

### 3. Install the key in the course and record its deployment

1. In Canvas, open the account or course where the tool should be available.
   Install an external app using the **Client ID** method and paste the
   Developer Key's client ID. Do not use the JWK `kid`, a client secret, or the
   legacy URL/XML (LTI 1.1) installer.
2. If your Canvas account lets you deploy the key at account level, do that to
   make it available across courses. A course-level install is also supported.
3. Copy the deployment ID shown for the installed app into
   `ESSAY_LTI_DEPLOYMENT_ID` in the server-private settings. InsQuire also
   accepts additional deployments signed by the same configured issuer and
   client ID; each course launch retains its own signed deployment mapping.
4. Save the Canvas key and open InsQuire from the course's **Course
   Navigation** link once as the course's teacher. This creates the linked
   InsQuire class and stores the numeric Canvas course ID. If a linked class
   was created before the custom field was added, open InsQuire from that
   Canvas course again.

The first Canvas instructor to launch a course owns its InsQuire class
mapping. Other teachers are not automatically granted teacher access. To use
an existing Education teacher account instead of the Canvas-created teacher
account, sign into the regular account, open
`https://ed.insquire.org/lti-account-link.php`, and generate a one-time code.
Then launch InsQuire from Canvas as the Canvas teacher, open the same account
link page, and redeem that code. It expires after 15 minutes and can only be
used once. Both accounts must be active Education teacher accounts; Personal
accounts cannot be merged.

### 4. Configure Canvas OAuth for publishing

LTI launches and Canvas REST API access are separate authorizations. For
publishing InsQuire assignments into Canvas modules:

1. Configure a Canvas OAuth/API Developer Key that allows the operations
   below. You may use a dedicated key; if so, configure its client ID
   separately.
2. Add this exact redirect URI to that OAuth key:
   `https://ed.insquire.org/canvas-oauth-callback.php`.
3. Store the OAuth client secret only in the server-private
   `/home/<user>/web/ed.insquire.org/private/config.php`:

   ```php
   'ESSAY_CANVAS_OAUTH_CLIENT_SECRET' => 'secret-from-the-canvas-oauth-key',
   // Optional; defaults to ESSAY_LTI_CLIENT_ID when omitted.
   'ESSAY_CANVAS_OAUTH_CLIENT_ID' => 'oauth-client-id',
   // Set this if the Canvas HTTPS origin differs from the default.
   'ESSAY_CANVAS_API_BASE_URL' => 'https://your-canvas-host',
   ```

4. Allow the teacher-authorized OAuth requests:
   - `GET /api/v1/users/self`
   - `GET /api/v1/courses/:course_id/modules`
   - `POST /api/v1/courses/:course_id/assignments`
   - `POST /api/v1/courses/:course_id/modules/:module_id/items`
5. Sign into InsQuire as the same teacher who launched the Canvas course and
   open **LTI settings** to connect the same Canvas account. To publish, open
   **Publish or import assignments**, select the linked class and module, and
   publish the assignment.

Do not put the client secret in source control, a browser, or the public
document root. Use Canvas's exact OAuth scopes, including HTTP methods and
endpoint paths, when the Developer Key enforces scopes.

### 5. Enable Canvas assignment sync and import

Assignment import requires an additional OAuth permission; ordinary publishing
does not. Class linking from **LTI settings** syncs all assignments in the
Canvas-linked source class before moving its data into the existing InsQuire
class. It follows Canvas assignment pagination and imports or updates
assignments idempotently, including title, description, rubric, due date, and
published/draft state. Ask the Canvas administrator to allow this exact read
scope on the OAuth/API Developer Key:

```text
url:GET|/api/v1/courses/:course_id/assignments
```

Then, from **LTI settings**, choose **Connect Canvas with assignment sync** or
**Reconnect Canvas with assignment sync** and approve the updated
authorization. Select the temporary Canvas-linked class and the existing
InsQuire class to keep, confirm the operation, and choose **Sync class and all
assignments**. If an import is interrupted, retry the action; assignment
imports are idempotent and the classes are linked only after assignment sync
finishes. Any partial sync remains in the temporary class until a successful
retry.

The Publish to Canvas page can also import an individual assignment. Directions
and rubrics are collapsed behind **Show directions/rubric** and imported
assignments have an **Open in Canvas** link. Native Canvas assignment imports
do not use InsQuire's LTI grade passback; LTI-linked assignments continue to
send grades and teacher comments through Assignment and Grade Services.

### 6. Verify the complete setup

Use a dedicated test Canvas course:

1. Launch InsQuire from Canvas as its instructor; confirm the class appears
   under InsQuire **Classes**.
2. Open the Publish to Canvas page, connect the same Canvas account, select
   the linked class, and verify the Canvas modules load.
3. Create and publish an InsQuire assignment, add it to a Canvas module, and
   confirm the assignment appears in Canvas.
4. Enable the assignment-read scope, connect Canvas from **LTI settings**,
   sync a Canvas-linked class into an existing InsQuire class, and verify all
   assignments and their directions/rubrics, due dates, and statuses.
5. Launch as a learner, submit work, grade and return it in InsQuire, then
   check the Canvas gradebook for the grade, overall teacher feedback, and
   comments on selected writing.
6. Confirm an unsupported Canvas role is rejected and a student cannot open a
   course before its teacher has linked it.

Troubleshooting:

- **“No Canvas-linked course was found”**: launch InsQuire from that Canvas
  course as its teacher first.
- **“This Canvas course launch did not include a numeric course ID”**: check
  that the Developer Key custom field is exactly
  `insquire_canvas_course_id=$Canvas.course.id`, save the key, and relaunch
  from that course.
- **`invalid_scope` mentioning assignments**: the optional
  `url:GET|/api/v1/courses/:course_id/assignments` permission is missing. Add
  it only if importing assignments, then reconnect with assignment import
  enabled.
- **Modules do not load / OAuth authorization fails**: verify the OAuth
  redirect URI, OAuth client ID and secret, Canvas API origin, and each
  required method/path permission above.
- **Canvas shows an authorization or launch error in an iframe**: check the
  server-side issuer, client ID, deployment ID, endpoint URLs, RSA private
  key, and published JWK before changing Canvas scopes.

When a Canvas launch creates a temporary InsQuire class, teachers can connect
that Canvas course to the InsQuire class they already use from **More → LTI
settings → Link a Canvas course to an InsQuire class**. Choose the Canvas-linked temporary class as the source
and the existing InsQuire class as the destination. Both must belong to the
signed-in teacher and the same institution. The destination keeps its name,
join code, and settings. Student memberships, assignments and submissions,
lessons and progress, messages, announcements, diagnostics, notifications,
and Canvas course links move to it; the temporary class is removed after
confirmation. If both classes have the same lesson, a student's completed
progress takes precedence; otherwise the most recently saved progress is
retained. Link the Canvas-created teacher account to the regular teacher
account first if those classes are owned by separate accounts.

Both `https://lms.insquire.org` and `https://ed.insquire.org` must remain
HTTPS end-to-end. The deployment script lints staged PHP files, applies the
additive institution-moderation, notifications, assignment-chat,
English-diagnostics, writing, submission-comment, user-calendar-events, LTI
Canvas, and Canvas publishing migrations using the server-private database
configuration, and stores timestamped backups under
`public_html/.deploy-backups/`.
