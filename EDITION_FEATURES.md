# Personal and Education features

The Personal site provides a work-plan calendar and can generate a current
month `.ics` download from its due dates. The Education site provides an
assignment calendar, class announcements, and private teacher/student
conversations, with an Education notification bell for approved new messages
and announcements. Institution admins can review held content within only
their own institution. Education profiles support a short bio and JPEG, PNG,
or WebP profile picture. See `EDUCATION_ADMINISTRATION.md` for institution
setup, the notification migration, and the server-side moderation term list.

For an existing database, back it up and apply
`database/edition_features.sql`. It adds the profile fields and messaging
tables without applying the cleanup statements in `database/migrations.sql`.
Then apply `database/institution_moderation.sql` once to add institutions,
scoped memberships, teacher invitations, class links, and moderation fields.
That migration is additive and keeps existing classes institution-less.
Apply `database/notifications.sql` to add the message/announcement inbox and
unread counts.
The PHP worker must be able to write to `public_html/storage/profile-pictures`;
this directory is outside the configured public document root. Profile images
are served through an authenticated endpoint limited to the owner and
classmates/teachers who share an active class.

The appearance preference (device, light, or dark) is managed in Profile
settings and saved to the user's account. The Google sign-in button is centered
within its form on both editions.

Personal and Education accounts have separate identities and data scope.
Apply `database/account_separation.sql` before deploying the matching account
code. Existing accounts remain Education accounts (including students without
a class yet); create a separate Personal account to use the Personal site.
Registration permits the same email address to have one Personal and one
Education account. Existing account rows are not automatically reclassified.
The two sites use distinct hostnames and session cookies; each login resolves
only an account for that site's scope. The shared database does not make one
site's account a login for the other site.

Personal accounts may register publicly on `insquire.org`. New Education
student accounts require a valid class join code and are enrolled in that
class as part of registration. New teacher accounts require a valid
institution invitation. Platform administrators are provisioned privately;
no role is selectable in public signup. A teacher's institution-admin
membership is distinct from the platform-admin account role. See
`EDUCATION_ADMINISTRATION.md` for invitation and class setup.

Stripe subscriptions, personal and institution paid-plan entitlements, and
platform-admin unrestricted AI grants are provided by the billing pages. For
an existing database, apply `database/payments_subscriptions.sql` after the
edition and institution migrations. Configure Stripe secrets and monthly Price
IDs outside the web root, then follow `BILLING_SETUP.md` to test Checkout and
signed webhooks before launch.

AdSense is disabled unless explicitly configured. Its one Personal Free
planner slot fails closed without a verified eligible account and the required
consent signal; Education and paid/unrestricted accounts do not load the
advertising script. Complete the manual publisher, consent, and privacy checks
in `BILLING_SETUP.md` before enabling it. Institution creation, teacher
invites, and institution-scoped message review are provided by the Education
administration pages after applying the institution migration; see
`EDUCATION_ADMINISTRATION.md` for setup and moderation behavior.
