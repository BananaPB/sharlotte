# Git & GitHub, for a solo dev who's only worked locally

You've used git locally — `add`, `commit`, maybe `checkout`. This is about what changes once GitHub (or any remote) and automation enter the picture, and why your repo currently shows what it shows.

## 1. Two different worlds: your computer and GitHub

Everything you've done so far — `git add`, `git commit`, `git log` — happens **only on your machine**, in the hidden `.git` folder inside [C:\Dev\sharlotte](.). GitHub knows nothing about any of it until you explicitly send it there.

```
YOUR COMPUTER                          GITHUB (github.com/BananaPB/sharlotte)
┌─────────────────────┐                ┌─────────────────────────────────┐
│  local repo          │                │  remote repo ("origin")          │
│  (.git folder)        │  git push ──▶  │                                    │
│                        │  ◀── git pull  │  the shared source of truth,       │
│  your commits live      │                │  where CI runs, reviews happen,    │
│  here first             │                │  and other people/bots see things  │
└─────────────────────┘                └─────────────────────────────────┘
```

`git push` is the verb that moves commits from your machine to GitHub. Nothing happens on GitHub's side — no build, no test, no review — until a push (or something that triggers off a push) happens.

## 2. Why branches, and why you have three of them

If you only ever commit to `main`, every experiment, every half-finished idea, and every typo fix all pile up on the one branch that's supposed to be your stable, trustworthy version. Branches exist to give risky or in-progress work its own space, away from `main`, until it's actually ready.

```
main         ──●──────────────────────●───▶   always stable, the "official" history
                 \                      ▲
feature/x         ●──●──●──●───────────┘        one line of work, merged back in when done
```

Your repo currently has three branches, and **this is completely normal**, not a mistake:

- **`main`** — the stable branch. Nothing lands here except through a reviewed, CI-checked merge.
- **`docs/current-state`** — a branch you (or a Claude session) created for a specific chunk of work (recording the repo's current state in the docs). It's *supposed* to be temporary: it exists to be merged into `main` and then deleted.
- **`dependabot/github_actions/...`** — a branch a bot created automatically (more on this in §6). Also temporary, also meant to be merged and deleted.

A repo with active work in it *always* has more than one branch. One branch (`main`) would mean either nothing is being worked on, or people are committing straight to it unreviewed — which is the thing branches exist to avoid.

## 3. `git push` vs. Pull Request — two different things

This is the part that's easy to conflate, because both involve "sending work to GitHub." They are not the same operation, and they're not even the same *kind* of thing.

| | `git push` | Pull Request (PR) |
|---|---|---|
| What it is | A **git** command | A **GitHub** feature (git itself has no concept of this) |
| What it does | Uploads commits from a local branch to the matching remote branch | Proposes merging one branch into another, and opens a page for discussion, review, and automated checks around that diff |
| Gated by anything? | No — if you have write access, it just happens | Can be blocked by rules: required reviews, required passing checks (this is exactly what your screenshot shows) |
| Where you run it | Your terminal | GitHub's web UI (or `gh pr create` from a terminal) |

Concretely: you `git push` your branch so GitHub *has* your commits. You then open a **Pull Request** to say "please merge `docs/current-state` into `main`." The push is a delivery; the PR is a proposal with a review process attached.

## 4. What a Pull Request actually gates

Opening a PR doesn't merge anything by itself. It creates a page where three independent things happen:

1. **CI (Continuous Integration)** — scripts defined in `.github/workflows/*.yml` run automatically on GitHub's own servers every time you push. In this repo: [tests.yml](../.github/workflows/tests.yml) (installs everything, lints, type-checks, runs the test suite) and [ai-pr-review.yml](../.github/workflows/ai-pr-review.yml) (an AI review of the diff).
2. **Review** — someone with write access reads the diff and approves or requests changes. On a team, that's a teammate. On this solo repo, that's meant to be you (see §7 for why that's currently broken).
3. **Branch protection rules** — settings on `main`, configured in *Settings → Branches* on GitHub, that say "don't allow a merge unless conditions 1 and 2 are satisfied." This is what produces the red X's and the greyed-out "Merge pull request" button in your screenshot — it's not an error, it's the rule working exactly as configured: *"I will not let you merge until CI is green and someone has approved."*

```
push commits ──▶ open PR ──▶  ┌─ CI runs (tests.yml, ai-pr-review.yml)  ─┐
                                 │                                           ├──▶ all green + approved ──▶ "Merge" button enabled
                                 └─ a reviewer approves                    ─┘
```

## 5. The GitHub Actions running in *this* repo

"GitHub Actions" is GitHub's automation engine: YAML files in `.github/workflows/` that say "on this event, run these steps on a fresh Ubuntu machine." Two are configured here:

- **[tests.yml](../.github/workflows/tests.yml)** → shows up as the **`ci`** check. On every push and PR, it checks out the code, installs PHP/Composer and Node, then runs `composer setup` (install dependencies, migrate the database, build the frontend) followed by `composer ci:check` (linters, static analysis, the test suite).
- **[ai-pr-review.yml](../.github/workflows/ai-pr-review.yml)** → shows up as the **`claude-review`** check. On every PR, it runs an AI code review against this repo's [CLAUDE.md](../CLAUDE.md) conventions and posts comments.

Both need to finish successfully (green ✓) before branch protection will let you merge — that's the "2 failing checks" line in your screenshot.

## 6. What Dependabot is

Dependabot is a bot **built into GitHub itself** (configured here via [.github/dependabot.yml](../.github/dependabot.yml)), not something external. Its job: watch your dependencies, and when a newer version exists, open a PR that bumps it — exactly like a human would, just automatically. In this repo it's configured to watch **GitHub Actions versions** (the `uses: actions/checkout@...` lines in your workflows) weekly.

That's why `dependabot/github_actions/...` exists as a branch and has its own open PR: Dependabot noticed `actions/checkout` had a new major version, made the change itself, and is now waiting — like any contributor — for CI to pass and for someone to review and merge it. It is not a sign of anything broken; it's a maintenance feature working as intended.

## 7. The daily loop, once this is all working

This project's `.claude/` agents exist so you're never guessing which command comes next — each one ends its report by telling you the next command to run. The full loop, git and agents interleaved:

```
/cto <question>                    OPTIONAL — unsure of direction/stack before starting? Ask first.
                                    Ends in a DECIDE ("then run /feature ..."), a DEFER, or a DON'T.

/feature <description>             Creates the branch (feature/... or fix/...) AND runs the dev
                                    agent to write the code. You don't run `git checkout -b`
                                    yourself — this command does it.
  ... review the diff yourself ...
  → suggests: /audit

/audit                              Quality & Security agent: Pint/Larastan/ESLint, N+1/CSRF/
                                    validation/auth checks on the diff. Auto-fixes what it can.
  ... fix anything flagged CRITICAL before continuing ...
  → suggests: /test  (or: fixes needed first)

/test                               QA agent: writes and runs Pest/Vitest coverage for the diff.
  ... if a test fails on a REAL bug (not a bad test), it hands that back to you to re-run
      /feature with a fix description — it never patches business logic itself ...
  → suggests: /cto (to get the exact git sequence)

/cto                                 Reviews the audit + test reports actually in context and
                                    gives you the real commands, filled in — not a template:
                                    git add <files>, a commit message, git push, gh pr create.

git push -u origin <branch>         send the branch to GitHub (from /cto's suggested commands)
                                    → open a Pull Request on GitHub (base: main)
                                    → CI runs automatically
                                    → you review + approve (or fix issues and push again)
                                    → "Merge pull request" once everything is green

git checkout main && git pull      bring the merged result back to your machine
git branch -d <branch>             delete the now-merged local branch

/doc                                 ONLY if the merged feature was structuring (new domain,
                                    new tables/routes) — updates architecture.md + changelog.md.
```

**When to create a branch**: never by hand — `/feature` does it as its first step, from a clean working tree. If you're mid-branch and want to keep going, just keep calling `/audit` / `/test` again after further edits; they always operate on "current branch vs. `main`", not a fixed snapshot.

**When to call `/cto`**: twice, typically — once *before* `/feature` if you're not sure this is the right approach at all (stack, infra, "should this even be built"), and once *after* `/test` passes, to close out the branch with the actual git commands. It's the only command in the loop that isn't tied to a fixed step, which is also why it's the one that reviews the finished work rather than producing more of it.

## 8. Quick glossary

| Term | Meaning |
|---|---|
| **Remote** | A copy of the repo hosted elsewhere (GitHub, here called `origin`) |
| **Push / Pull** | Send your commits to the remote / fetch the remote's commits into your local repo |
| **Branch** | A named, independent line of commits |
| **Pull Request (PR)** | A GitHub proposal to merge one branch into another, with review + checks attached |
| **CI (Continuous Integration)** | Scripts that automatically build/lint/test your code on every push, on GitHub's servers |
| **GitHub Actions** | GitHub's automation system that runs CI (and anything else) from `.github/workflows/*.yml` files |
| **Check** | One CI job's pass/fail result, shown on a PR (`ci`, `claude-review` here) |
| **Branch protection rule** | A setting on a branch (usually `main`) that blocks merging until conditions are met |
| **Dependabot** | GitHub's built-in bot that opens PRs to bump outdated dependencies |
| **GitHub App** | A separate installable integration (distinct from a workflow file) that a repo owner must explicitly grant access to — see §9.2 |

---

## 9. What's actually broken in *this* repo, right now

Three independent problems are stacked in your screenshot. None of them is "the initial commit failed" in the sense of git losing anything — nothing is lost, your commits are all there. What's failing is the *gate* GitHub puts in front of merging. Here's each one, and how I confirmed it, so you can check my work and try the fix yourself.

### 9.1 "Review required" — you structurally cannot approve your own PR

Branch protection on `main` is configured to require **at least one approving review from a reviewer with write access** before a merge is allowed. On a team, a teammate does this. On a solo repo, you are the only person with write access — and **GitHub does not allow a PR author to approve their own PR**, by design, regardless of permissions.

This means: as currently configured, *no PR you open can ever be merged*, not because anything is wrong with your code, but because the rule assumes a second person exists.

**How to verify it yourself**: GitHub → your repo → *Settings → Branches* (or *Rules → Rulesets*) → the rule protecting `main` → look at "Require a pull request before merging" → "Require approvals."

**The shape of the fix** (pick one, both are normal for a solo repo): lower the required approval count to 0, or enable the option that lets specific people (you) bypass the pull-request requirement, while keeping the "require CI to pass" rule in place. You still get the safety net of CI gating every merge — you're only removing the part of the rule that assumes a reviewer other than you exists.

### 9.2 `claude-review` fails — the GitHub App was never installed

I confirmed this directly from GitHub's own failure annotation on the check run, not a guess:

> **Action failed with error: Claude Code is not installed on this repository. Please install the Claude Code GitHub App at https://github.com/apps/claude**

The workflow file [ai-pr-review.yml](../.github/workflows/ai-pr-review.yml) is correctly written — but it depends on the **Claude Code GitHub App** being installed and granted access to this repository, which is a separate, one-time step done through GitHub's UI (distinct from anything in the repo itself, which is why no amount of editing files fixes it).

**The shape of the fix**: visit the URL in the error message, install the app, and grant it access to `sharlotte`. Re-run the check afterward (or push a new commit) to confirm.

### 9.3 `ci` fails — confirmed down to the exact step, two separate causes

I reproduced the CI steps locally (`composer install`, `artisan migrate`, `npm install`, Pint) against a clean checkout of `main` to isolate this rather than guess. Findings:

**On `main` itself, before either PR existed** — the very first push already failed `ci`. I confirmed via GitHub's job API that the failure is inside the **"Setup Application"** step (`composer setup`, which runs `composer install` → `.env` setup → `artisan migrate` → `npm install` → `npm run build`). I reproduced `composer install`, the migration, and `npm install` successfully by hand — all three are innocent. That narrows it to `npm run build` (the frontend build, `vp build` under the hood). I could not get a clean final answer on the exact line past that point — my local shell has an unrelated Windows-only problem spawning that build tool, which is a dead end for reproducing the Linux CI environment further from here.

**Your move to see the exact line**: on the PR page, click the failing `ci` check → it opens the GitHub Actions run → click the red **"Setup Application"** step to expand it → read the last ~20 lines before "Process completed with exit code 1." That log is the real, authoritative Linux output — trust it over my local reproduction attempt. This is also just the general skill for every future red check: **the expandable step in the Actions run *is* the error message; the PR page only ever shows you pass/fail, never why.**

**On `docs/current-state` specifically, an additional, confirmed cause on top of the above** — I reproduced this exactly, with the real error text:

```
npm error code EBADDEVENGINES
npm error EBADDEVENGINES Invalid devEngines.packageManager
npm error EBADDEVENGINES Invalid name "pnpm" does not match "npm" for "packageManager"
npm error EBADDEVENGINES { current: { name: 'npm', version: '11.13.0' },
npm error EBADDEVENGINES   required: { name: 'pnpm', version: '12.4.2', onFail: 'download' } }
```

The last commit on that branch added this to [package.json](../package.json):

```json
"devEngines": {
    "packageManager": { "name": "pnpm", "version": "12.4.2", "onFail": "download" }
}
```

This tells `npm` itself: *"refuse to run unless you are pnpm."* But nothing else was updated to match — [.github/workflows/tests.yml](../.github/workflows/tests.yml) never sets up pnpm, and `composer.json`'s `setup` script still calls plain `npm install` / `npm run build`. The pin was added without switching what actually runs, so `npm` refuses to install anything, immediately, before the frontend build even starts.

**The shape of the fix**: pick one consistently — either remove the `devEngines.packageManager` pin (stay on plain npm, delete the stray [package-lock.json](../package.json) since [pnpm-lock.yaml](../pnpm-lock.yaml) would no longer be needed either), or actually switch the project to pnpm (add a `pnpm/action-setup` step to `tests.yml`, and change `composer.json`'s `setup` script to call `pnpm install` / `pnpm run build` instead of `npm`). Either is fine; what's not fine is the current in-between state where one file demands pnpm and every script still calls npm.

### Try it yourself

A reasonable order, in a repo with no risk of losing work since nothing here deletes commits:

1. Fix branch protection (§9.1) — otherwise nothing can ever merge no matter how green the checks are.
2. Install the Claude Code GitHub App (§9.2).
3. Open the `ci` step log on the PR yourself and read the real error under "Setup Application" — confirm whether it matches the pnpm/npm mismatch (§9.3) or is something else in `npm run build`.
4. Decide npm or pnpm, make the two files agree, push a new commit to the same branch (PRs update automatically on a new push — no need to open a new one), and watch the checks re-run.

## 10. Opening this repo to outside contributors, later

When the time comes, the review-approval rule from §9.1 doesn't get *disabled* — you make yourself exempt from it while leaving it on for everyone else. That's a different, better fix than the one in §9.1's "shape of the fix," and worth doing this way from the start:

- **Classic branch protection**: uncheck **"Include administrators."**
- **Newer Rulesets** (*Settings → Rules → Rulesets*): add yourself to the ruleset's **bypass list**.

Either way, the rule itself — "1 approval required" — stays configured exactly as it should be for a contributor's PR. You just stop being subject to it. Set it up this way now (or whenever you fix §9.1) and there's nothing left to reconfigure the day someone else opens a PR.

Three things to set up specifically when contributions start arriving, in order of how much each one matters:

1. **Gate first-time contributors' CI runs.** *Settings → Actions → General → "Fork pull request workflows"* → **"Require approval for first-time contributors"** (GitHub's own default). A stranger's first PR then needs you to click "Approve and run workflows" before any CI executes on it — after that first reviewed PR, later ones from the same person run automatically. This stops someone from using your CI minutes, or probing your CI environment, via a PR you haven't looked at yet.
2. **Never switch [ai-pr-review.yml](../.github/workflows/ai-pr-review.yml) from `pull_request` to `pull_request_target`.** The current trigger means a PR from a fork runs with **no access to your repo secrets**, including `CLAUDE_CODE_OAUTH_TOKEN` — this is GitHub's default protection, and it's already correct. `pull_request_target` runs the workflow with your secrets available *and* against the base repo's permissions while still checking out the contributor's code — that combination is the single most common way real open-source projects get their secrets exfiltrated, via a crafted first PR. If the AI review simply doesn't run on external PRs, that's the safe failure mode, not a bug to "fix" by switching the trigger.
3. **Required status checks need no changes.** `ci` and `claude-review` already apply identically to every PR, yours or a contributor's — that's the mechanism that will actually vet outside contributions.

The licensing side of accepting contributions safely (can people legally reuse and build on what they and you have written) is already settled — see [decisions.md](decisions.md) entry 2, MIT for code, CC-BY 4.0 for the dataset.
