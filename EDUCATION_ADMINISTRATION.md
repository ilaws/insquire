# Education institutions and message review

See `DEPLOYMENT.md` for the complete deployment checklist.

## Production deployment

1. Back up the production database and test this change against a staging copy.
2. Apply `database/account_separation.sql` before deploying the account-scope
   code. Existing accounts stay Education accounts; new Personal accounts are
   separate user records, even when they use the same email address.
3. If the existing installation has not yet received Education messaging, apply
   `database/edition_features.sql` first. Do not rerun the cleanup-oriented
   `database/migrations.sql` against production.
4. Apply `database/institution_moderation.sql` to the application database. It
   only creates/adds schema objects, preserves existing classes with a `NULL`
   institution, and can be safely rerun.
5. Apply `database/notifications.sql` to create the notification inbox and
   unread state. This migration is additive and safe to rerun.
6. Deploy the matching PHP files to the Education application host and test a
   teacher invite, class join-code flow, a normal and held private message, a
   normal and held announcement, and the recipient's unread bell count.
7. Privately provision the first school teacher account on the Education host.
   Public signup cannot create teacher or platform-admin accounts. The
   provisioned teacher creates an institution in **Institutions** and becomes
   its scoped institution administrator. They can generate single-use,
   seven-day teacher invitations. Invitations may be email-targeted; share
   their links privately. A new teacher can create an account from a valid
   invitation, and an existing teacher can sign in to accept one.
8. Teachers create classes under an institution they belong to. Give students
   the class join code. A new Education student must provide a valid class join
   code during signup; the account is created as a student and enrolled in
   that class (and its institution, if it has one) in the same transaction.
   Existing students can continue using **Join Class** after signing in.
   Institution administrators can link an existing class when its teacher
   already belongs to that institution; current class students are added to
   the institution when the class is linked. Institution-less legacy classes
   keep their existing behavior until linked.

The Personal site at `insquire.org` accepts public Personal account signup.
The Education site at `ed.insquire.org` accepts only student signup with a
valid class join code or teacher signup from an institution invitation.
Platform administrators are provisioned privately; neither `student`,
`teacher`, nor `admin` is a user-selectable account type. An institution
administrator is a teacher whose institution membership has the `admin` role;
that is separate from the platform-wide `admin` account role. Existing
Education accounts are grandfathered and are not reclassified by this signup
policy.

The institution-admin role is stored on that institution's membership, not as a
platform-wide administrator privilege. A platform admin has no review access
unless separately made an administrator of that institution. Keep the PHP
workers on the Education host pointed at the same database and use HTTPS.

## Message flagging and review

No AI is used for moderation. `ESSAY_MESSAGE_MODERATION_TERMS` is a
comma-separated server-side setting, supplied through the private PHP
configuration or environment. The default list is maintained in
`config/message-moderation-terms.php` and includes profanity, insults, abusive
phrases, and spelling variants. Teacher announcements and private messages use
the same filter. Any configured terms are added to that default list. To deploy
a changed default list, upload both this file and `config/config.php`; changing
SQL or only the private config is not required.
Matching is case-insensitive, Unicode-aware, and whole-word only, avoiding
substring matches (for example, `class` does not match `ass`). Configure the
list for the institution's language and policy. This is a simple hold filter,
not a complete safety classifier; administrators should review the queue.
Some supplied entries also occur in ordinary identity, health, and education
contexts. Because matching creates a pending hold for administrator review
rather than automatically rejecting content, moderators should approve
legitimate use and consider removing entries that cause excessive false
positives.

New private messages and class announcements are checked in every class.
Matching content in institutional classes is stored as pending and visible to
its sender, but hidden from recipients and students until an administrator of
that same institution approves it. Rejection keeps the content withheld.
Matching content in institution-less legacy classes is rejected at submission,
because no institution administrator is available to review it.

The queue displays class/sender/date metadata only. An administrator must open
one pending item to view its body and approve or reject it. Every queue query
and decision checks the administrator's membership against the message's
institution; global platform-admin status is not a bypass.

Teacher feedback on student submissions is separately checked for common
profanity before it can be saved or returned. The check is case-insensitive and
whole-word based; it rejects the feedback rather than silently masking words.
It is a basic language filter, not a substitute for school review.

## Notifications

Students and teachers see a notification bell and unread count in the Education
navigation. Approved private messages notify their recipient; approved class
announcements notify active class members other than the author. Held content
does not notify anyone until an institution administrator approves it. The
notifications page links to the conversation or announcement and allows each
notification to be marked read. The count refreshes on page load; this is not a
browser push service; when a page is open it polls for count updates once per
minute.
