# InsQuire

**An AI writing coach and classroom workspace for students and teachers.**

Live: [ed.insquire.org](https://ed.insquire.org) (Education). The Personal edition isn't open on the public site yet, but it's fully included here and runs locally.

InsQuire helps students plan their work and become better writers. The AI
coaches them on their own drafts instead of writing for them. It runs as two
sites built from one codebase:

| | **Personal** | **Education** |
|---|---|---|
| Who | Independent learners | Schools: students, teachers, admins |
| Sign-up | Open | Students need a class code; teachers need an invite |
| Highlights | Work planner and calendar (`.ics` export), Grammarly-style inline draft review, AI follow-up questions | Classes, assignments, AI feedback on submissions, AI-assisted grading, gradebook, English (CEFR) diagnostics, announcements, moderated messaging, Canvas LMS (LTI 1.3) integration |

### Features

- **Inline AI draft review.** Suggestions are highlighted in the draft itself
  with color-coded priorities. Each one has an explanation and an "Apply" button,
  and the review marks itself stale when you keep editing.
- **AI feedback and AI grading.** Students get rubric-aware feedback on
  submissions. Teachers get a suggested grade and evaluation that they review
  before anything is released.
- **English diagnostics.** A self-assessment that gives an approximate CEFR
  level, plus teacher-assigned diagnostics with class-level reporting.
- **Classroom tools.** Institutions, classes, rosters, assignments, a gradebook,
  calendars, notifications, announcements, and private messages with
  institution-level moderation.
- **Canvas integration.** LTI 1.3 launch and deep linking, plus publishing
  assignments to Canvas.
- **Billing.** Stripe subscriptions and AI usage limits per plan (optional).
- **Accessibility.** Light, dark, and high-contrast themes, adjustable font
  size, and a reduced-motion setting.

### Tech stack

PHP 8.3 · MariaDB · Angular 21 + Tailwind CSS 4 (home, sign-in, and dashboard
screens) · server-rendered PHP pages · DeepSeek (OpenAI-compatible) chat API ·
Apache

---

## Run it locally (Docker, recommended)

**Requirements:** [Docker Desktop](https://www.docker.com/products/docker-desktop/)
(or Docker Engine with Compose v2).

```sh
git clone https://github.com/ilaws/insquire.git
cd insquire
cp .env.example .env            # edit the passwords; add an AI key if you have one
docker compose up -d --build
```

The first start builds the Angular frontend and creates the database, which
takes a few minutes. Then open:

- **Personal site:** <http://localhost:8080>
- **Education site:** <http://127.0.0.1:8080>

The app chooses its mode from the hostname, so both sites run from the same
container. They keep separate accounts and separate sessions.

### Try it out

**Personal:** open <http://localhost:8080>, register, and start a plan in the
planner.

**Education:** teacher and admin accounts are invite-only, so create demo
accounts from the command line:

```sh
docker compose exec app php scripts/create-user.php education teacher "Demo Teacher" teacher@example.test password123
docker compose exec app php scripts/create-user.php education admin   "Demo Admin"   admin@example.test   password123
```

Sign in at <http://127.0.0.1:8080> as the teacher, then go to **Institutions**,
create an institution, and use **Create Class**. Share the class join code so
students can register with it.

### AI features

Set `ESSAY_DEEPSEEK_API_KEY` in `.env` (get a key at
[platform.deepseek.com](https://platform.deepseek.com)), then run
`docker compose up -d` again. Without a key, everything else still works and the
AI buttons say that AI isn't configured.

### Stopping and resetting

```sh
docker compose down        # stop the containers
docker compose down -v     # stop and delete the database (it is rebuilt on the next start)
```

---

## Run it without Docker

1. Install **PHP 8.3+** (with `pdo_mysql`, `curl`, `mbstring`, `openssl`),
   **MariaDB 10.5+**, and **Node.js 20+**. The schema uses MariaDB-only syntax,
   so stock MySQL will not work.
2. Create a database, then apply the SQL files in the order listed in
   [`docker/initdb.sql`](docker/initdb.sql):
   ```sh
   cd database
   for f in $(grep -oE '[a-z_]+\.sql' ../docker/initdb.sql); do mariadb insquire < "$f"; done
   ```
3. Build the frontend into `public/app/`:
   ```sh
   cd frontend && npm ci && npm run build:deploy
   ```
4. Copy `config/local.example.php` to `config/local.php` and fill in your
   database credentials. Environment variables also work, and they take
   precedence.
5. Point an Apache virtual host at `public/` with `AllowOverride All` and
   `mod_rewrite`, `mod_headers`, and `mod_setenvif` enabled.

For a production checklist (HTTPS, separate hosts, Google sign-in, Stripe,
Canvas), see [DEPLOYMENT.md](DEPLOYMENT.md).

---

## Configuration and secrets

All configuration comes from environment variables, or from a PHP settings file
that lives outside the web root. **No secrets are stored in this repository.**

- [`.env.example`](.env.example) lists every setting, with comments.
- Only the database settings are required. Each optional integration (AI,
  Google sign-in, Stripe, Canvas, AdSense) turns itself off when its settings
  are blank.
- `.env`, `config/local.php`, and `private/` are git-ignored.

More docs: [AI configuration](AI_CONFIGURATION.md) ·
[Education administration](EDUCATION_ADMINISTRATION.md) ·
[English diagnostics](ENGLISH_DIAGNOSTICS.md) ·
[Personal writing review](PERSONAL_WRITING_REVIEW.md) ·
[Edition features](EDITION_FEATURES.md)

## Project layout

```
config/        App configuration (reads env vars; no secrets)
includes/      Shared PHP: auth, CSRF, AI client, billing, LTI, notifications
public/        Web document root: PHP pages, JSON API (api/v1), assets
  app/         Built Angular bundle (generated, git-ignored)
frontend/      Angular 21 + Tailwind source
database/      Schema, migrations, and seed data
docker/        Docker database bootstrap
scripts/       CLI helpers (create-user.php)
storage/       Runtime uploads (outside the web root, git-ignored)
```

## License

[MIT](LICENSE)
