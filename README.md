# Ignyte Site Manager

AI-powered multi-tenant SaaS portal for website content management. Clients submit plain-English change requests, which are transformed into Git-based edits with full preview, approval, and audit trail.

## Architecture

```
Client prompt → AI edit (Claude) → Git branch → Preview deploy → Human approval → Merge → Production
```

**No client prompt ever writes directly to production.** Every change is diff-reviewable, audited, and revertible.

## Tech Stack

- **Framework**: Next.js 16 (App Router) + TypeScript + Tailwind CSS
- **Database**: PostgreSQL (Neon/Supabase) + Prisma ORM
- **Auth**: Auth.js (NextAuth) with credentials + JWT sessions
- **Git**: GitHub API via Octokit (GitHub App, least-privilege)
- **AI**: Anthropic Claude (claude-sonnet-4-6) for content edits
- **Storage**: Cloudflare R2 (S3-compatible) for assets & context docs
- **Billing**: Stripe subscriptions + quota metering
- **Deploy**: Vercel (client site previews + production)

## Setup

### 1. Clone & install

```bash
git clone https://github.com/asam89/ignyte-site-manager.git
cd ignyte-site-manager
npm install
```

### 2. Environment variables

```bash
cp .env.example .env
```

Fill in all required values (see `.env.example` for descriptions).

### 3. Database

```bash
npx prisma migrate dev
npx prisma db seed
```

This creates all tables and seeds:
- Staff user: `asam@ignyteconsulting.com` / `ignyte2026!`
- Client org: Baseera with `admin@baseera.ca` / `baseera2026!`
- Site: baseera.ca (configured for Next.js adapter)

### 4. GitHub App

1. Create a GitHub App at https://github.com/settings/apps/new
2. Permissions: Contents (read/write), Pull Requests (read/write), Deployments (read)
3. Install on the client repo (e.g., `asam89/baseera-ca`)
4. Generate a private key and set `GITHUB_APP_PRIVATE_KEY` in `.env`
5. Set `GITHUB_APP_TOKEN` (installation token)

### 5. Run

```bash
npm run dev
```

Visit http://localhost:3000

## Roles

| Role | Permissions |
|------|-------------|
| `ignyte_staff` | Full access: all orgs, all sites, onboard, review queue, approve flagged changes |
| `client_admin` | Own org: submit requests, approve/reject, manage users, billing |
| `client_editor` | Own org: submit requests, view status |

## Change Request Pipeline

1. **Submit** — Client types a plain-English request
2. **Generate** — Claude edits only allowed files, respecting content-only constraints
3. **Validate** — Diff checked against allowlist + structural-change rules
4. **Branch & PR** — Changes committed to a new branch, PR opened
5. **Preview** — Vercel auto-deploys the branch for visual review
6. **Approve/Reject** — Admin reviews diff + preview
7. **Merge** — PR squash-merged to production
8. **Revert** — One-click rollback via revert commit

## Onboarding baseera.ca

Prerequisites:
- baseera.ca must be a Git-backed site (repo + Vercel deployment)
- GitHub App installed on the repo
- Editable paths configured in the Site record

If baseera.ca is currently on a no-code builder without API access, it must first be migrated to a Git repo before onboarding.

## Project Structure

```
src/
├── adapters/           # SiteAdapter interface + implementations
│   ├── types.ts        # Interface definition
│   ├── git-nextjs-adapter.ts  # GitHub + Vercel adapter
│   ├── wordpress-adapter.ts   # WordPress stub
│   └── index.ts        # Factory function
├── app/
│   ├── (auth)/         # Login/signup pages
│   ├── (dashboard)/    # Authenticated pages
│   │   ├── sites/      # Site list + detail + request submission
│   │   └── admin/      # Staff admin (onboard, review queue)
│   └── api/            # Route handlers
│       ├── auth/       # NextAuth handlers
│       ├── sites/      # Site CRUD
│       └── change-requests/  # Request pipeline
├── components/         # Shared UI components
│   ├── ui/             # Button, Input, Card
│   └── sidebar.tsx     # Dashboard navigation
├── lib/
│   ├── auth.ts         # Auth.js configuration
│   ├── prisma.ts       # Prisma client singleton
│   ├── ai.ts           # Claude integration + context gathering
│   └── diff-validator.ts  # Allowlist + structural-change validation
└── generated/prisma/   # Prisma client (generated)
```

## Security

- All secrets in environment variables — never committed
- GitHub App with least-privilege per-repo installs
- AI calls + Git operations server-side only
- Tenant isolation enforced in every database query
- Rate limiting on request submission
- File upload validation (type, size, sanitized filenames)
