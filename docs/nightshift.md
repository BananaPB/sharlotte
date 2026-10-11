# Nightshift — building features while nobody is watching

> Setup guide and daily use. The decision behind it is `decisions.md` entry 12.

**In one sentence**: Hermes is the remote control and the clock, Claude Code thinks and builds, GitHub enforces the rules.

```
Telegram ──► Hermes ──► nightshift.sh cto "…"  ──► claude -p "/cto …"  (grilling → briefs as issues, label nightshift:ready)
                                                         │  you say "go"
Hermes cron 23:07 ──► nightshift.sh start ──► run (plain bash, not an agent)
                         for each ready issue, oldest first, one at a time, until MAX_FEATURES or STOP_LAUNCHING_AT:
                           fresh container + fresh clone of main
                           └─ claude -p "/feature #n" → dev (tdd) → /audit (quality ∥ spec) → /test (qa) → PR → CI + claude-review
                           label the issue, comment the result on it
                         check the night's PRs don't conflict with each other (git merge-tree)
Hermes cron */15 min ──► nightshift.sh watch   → Telegram only if something needs you
Hermes cron 07:30    ──► nightshift.sh report  → "✅ #12 ready · ✋ #13 needs you · no conflicts"
                                                         │  you review + merge on GitHub (unchanged)
Hermes cron 13:07    ──► nightshift.sh docs    → /doc after new merges → a docs PR
```

What stays exactly as before: you decide what gets built, you review, you merge. What changes: the build happens without you, in a Linux container (so `npm run check:fix` finally works), as a bot that **cannot** merge.

---

## One-time setup

Budget about an hour and a half, in this order. Steps 1–2 are the safety net; don't skip them to get to the fun part.

### 1. A bot account for the agents

Today the agents push as _you_, and you can merge anything. At night that's the wrong identity.

1. Create a second GitHub account, e.g. `sharlotte-bot` (a free account; GitHub allows one machine account per person).
2. On `BananaPB/sharlotte`: _Settings → Collaborators → Add people_ → the bot, role **Write**. Accept the invitation from the bot account.
3. Logged in as the bot: _Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate_. Scope: **`repo` only** — not `workflow` (so it can't change CI), not `admin:*`. Expiration: 90 days, and put a reminder in your calendar.
   _Why classic and not fine-grained_: a fine-grained token can only reach repos its own account (or org) owns, not a repo it's a collaborator on. Since the bot is a collaborator on this one repo and owns nothing, `repo` scope reaches exactly that.
4. Note its numeric ID for the commit email: `gh api users/sharlotte-bot --jq .id` → `BOT_EMAIL=<id>+sharlotte-bot@users.noreply.github.com`.

### 2. Protect `main` (server-side — this is the real guarantee)

_Settings → Rules → Rulesets → New branch ruleset_, target `main`, enforcement **Active**:

- **Restrict deletions**, **Block force pushes**.
- **Require a pull request before merging**: 1 required approval, **Require review from Code Owners** (`.github/CODEOWNERS` names you), **Dismiss stale approvals when new commits are pushed**.
- **Require status checks to pass**: `ci` and `claude-review`.
- **Bypass list**: add **yourself** (Repository admin role) — not the bot. That also fixes `git-github-workflow.md` §9.1: your own PRs merge without an approval you can't give yourself, while the bot's PRs need yours.

Honest limit: on a personal (non-organization) repo, GitHub can't stop a Write collaborator from clicking merge on a PR that is _already approved and green_. The agents' permissions deny `gh pr merge`, and "dismiss stale approvals" means any later push needs a new approval — so approve and merge in one go and the window is closed. If you ever want that last gap closed server-side, move the repo to a free organization, where rulesets can restrict who merges.

### 3. A Claude token for unattended runs

On your PC: `claude setup-token`, approve in the browser, copy the token it prints (it's shown once). It uses your subscription; the runs count against your usual limits.

### 4. The runner, on the machine that runs Hermes

Needs Docker (with the compose plugin), `git`, `gh`, `jq`, `flock` (util-linux) and ~10 GB of disk. Run as a normal user, not root.

```bash
mkdir -p ~/nightshift ~/.config/nightshift
git clone https://github.com/BananaPB/sharlotte.git ~/nightshift/sharlotte
cp ~/nightshift/sharlotte/scripts/nightshift/env.example ~/.config/nightshift/env
chmod 600 ~/.config/nightshift/env
nano ~/.config/nightshift/env           # bot token, BOT_EMAIL, Claude token, TRUSTED_AUTHORS

NS=~/nightshift/sharlotte/scripts/nightshift/nightshift.sh
$NS labels                              # creates nightshift:ready/running/pr-ready/escalated/failed
docker compose -p nightshift -f ~/nightshift/sharlotte/.devcontainer/compose.yaml build app
```

The image build is the first real test of `.devcontainer/Dockerfile` — if a step fails, the error names it.

### 5. A first run by hand, in the foreground

Pick something small and boring. Open an issue with the **Feature brief** template, fill every section, add the label `nightshift:ready`, then:

```bash
$NS run                                 # foreground: watch it go
ls ~/nightshift/state/nights/*/         # issue-N.json (result), issue-N.log (setup + stderr)
```

You should end with a PR from `sharlotte-bot`, CI running, a comment on the issue with the result. Read the transcript of a first run before trusting a second one.

### 6. Hermes: the clock and the alerts

```bash
$NS install-hermes                      # writes ~/.hermes/scripts/nightshift-{start,watch,report,docs,audit}.sh

hermes cron create "7 23 * * *"   --no-agent --script nightshift-start.sh  --deliver telegram --name nightshift-start
hermes cron create "*/15 * * * *" --no-agent --script nightshift-watch.sh  --deliver telegram --name nightshift-watch
hermes cron create "30 7 * * *"   --no-agent --script nightshift-report.sh --deliver telegram --name nightshift-report
hermes cron create "7 13 * * *"   --no-agent --script nightshift-docs.sh   --deliver telegram --name nightshift-docs
```

All four are `--no-agent`: no model, no tokens — Hermes just runs the script and forwards what it prints. Each one prints **nothing** when there's nothing to say, which Hermes treats as a silent tick. `start` exits silently on an empty queue, so the nightly job costs nothing on nights you didn't plan. Times are the Hermes host's local time — check its timezone (`date`) matches Paris.

For a daytime run: ask Hermes "lance le nightshift à 9h" — it creates a one-shot job on `nightshift-start.sh` the same way.

### 7. The CTO on Telegram

Create `~/.hermes/skills/sharlotte-cto/SKILL.md`:

```markdown
---
name: sharlotte-cto
description: Relay messages about the sharlotte project to its CTO (Claude Code /cto). Use whenever the user talks about sharlotte, its roadmap or features, or starts a message with "cto".
---

You are a relay, not the CTO. Never answer sharlotte questions yourself.

- Pass the user's message, verbatim, through stdin:
  `~/nightshift/sharlotte/scripts/nightshift/nightshift.sh cto - <<'MSG'` … message … `MSG`
- Send back its output verbatim. It can take a minute; say "le CTO réfléchit…" first if your platform allows.
- "cto nouveau" / "nouvelle discussion" → run `nightshift.sh cto-new` instead.
- If the reply contains a line `NIGHTSHIFT READY: … — start <time>` and the time isn't tonight's 23:07,
  create a one-shot cron job at that time: `--no-agent --script nightshift-start.sh --deliver telegram`.
```

The CTO runs as the bot, in its own clone, with read access plus two kinds of writes: creating `nightshift:ready` issues, and recording an agreed decision through a `docs/decision-N` PR. It's the same `.claude/commands/cto.md` you use in VS Code — one CTO, not two.

### 8. Optional: the security audit

Not part of the PR loop; run it at milestones (end of a phase, before the first public deploy, before the SaaS opens).

```bash
git clone https://github.com/cloudflare/security-audit-skill ~/nightshift/security-audit-skill
$NS audit                               # background, several hours, many subagents: plan it for a night alone
```

Results land in `~/nightshift/state/audits/run-*/` (`REPORT.md`, `findings.json`). Turn confirmed findings into briefs. It's deliberately not installed in the repo: it triggers on phrases like "security audit", which `/audit` would hit on every PR.

### 9. The same container in VS Code (optional, recommended)

Windows: WSL2 + Docker Desktop + the _Dev Containers_ extension, clone the repo **inside WSL**, open it, "Reopen in Container". Same PHP/Node/Postgres as CI and as nightshift, and `npm run check:fix` works (`git-github-workflow.md` §9.4 becomes history). Log in to Claude once inside the container; the login is kept in a volume.

---

## Daily use

**Plan a night** (Telegram or VS Code): "cto: programme une nuit avec X et Y". The CTO grills each feature until every brief section is settled, checks they don't touch the same zones, shows you the briefs, and creates the issues only after an explicit "go".

**In the morning**: the report tells you what's ready. Review and merge as usual. If two PRs of the night conflict, merge one, then ask the bot or click "Update branch" on the other.

**An escalation** (✋): the run stopped because a decision is yours. The question is in the issue comment. Answer it **by editing the issue body** (comments are never read, on purpose), then put `nightshift:ready` back. A failed run (❌) works the same way once you've looked at `issue-N.log`.

**Stop everything**: ask Hermes to pause the `nightshift-start` job, or simply remove the `nightshift:ready` labels (an empty queue never starts). A run in progress: `docker ps`, `docker rm -f nightshift-feature-…`.

---

## The A/B check (first two weeks)

This setup changes two things at once: _where_ the pipeline runs, and _how_ it builds (test-first `dev`, a `spec` reviewer, an adversarial `qa`). To know whether the second part is worth it, compare four similar-sized features: two run with the pipeline as it was (`git checkout <commit before this PR> -- .claude CLAUDE.md` on a scratch branch, run in VS Code), two with this one. Note for each:

|     | CI rounds to green | blocking claude-review findings | what _you_ found in review | bugs after merge | cost (`total_cost_usd` in `issue-N.json`) |
| --- | ------------------ | ------------------------------- | -------------------------- | ---------------- | ----------------------------------------- |

If the new way doesn't clearly win, keep the simpler parts and drop the rest — and record it as a new entry in `decisions.md`.
