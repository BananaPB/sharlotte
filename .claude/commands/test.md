---
description: Launches the QA/Test agent to try to break the feature from its brief, then commits, opens the PR and watches it to green
---

Invoke the **qa** sub-agent (see `.claude/agents/qa.md`) on the active branch, giving it the **brief verbatim** (the one `/feature` received).

## Steps

1. Verify we're actually on a feature branch (not `main`).
2. Invoke the `qa` sub-agent with the brief and the diff between `main` and the current branch as its scope.
3. Display the full report: attack list, tests added, Pest/Vitest results, and any failure linked to a code bug.

## Then

This is a standing authorization to chain all the way through to an open, watched PR without waiting for the user at each step (`docs/decisions.md`, entry 7, 2026-09-24) — the human checkpoints are what to build and the final PR review, not every intermediate step. **Merging is never part of this chain — that's always an explicit user action on GitHub.**

- **Tests fail on a real code bug** (not a test-writing issue): dispatch `dev` directly with the brief and the specific failure, then re-run this test step on the updated code. Cap it at 2 fix-rounds on the same failure, or 4 total fix-rounds on this step overall — whichever comes first, so a fix that introduces a _different_ new failure each round doesn't dodge the cap by never repeating the same one. Past that: stop and report to the user directly instead of retrying (status `escalated` in an unattended run).
- **Tests pass**: commit, push, open the PR, and watch it through to green:
    1. **Format before committing**, so CI's formatting gate doesn't cost a round-trip: `./vendor/bin/pint` and `npm run check:fix`. Both work in the devcontainer and the nightshift container. Only on a Windows host shell (Git Bash) does `npm run check:fix` fail for an environment reason (`docs/git-github-workflow.md` §9.4) — there, skip it and rely on CI's gate.
    2. `git status --short` for the real file list. Group into logical, atomic commits — never `git add -A`/`git add .` — with `type(scope): description` messages (CLAUDE.md §7).
    3. `git push -u origin <branch>` (plain `git push` if already tracking). Then `gh pr create` — unless this branch already has an open PR, in which case the push alone updates it; never open a second PR for the same branch. The PR body contains: `Closes #<n>` when the brief is an issue; one line per acceptance criterion with the test that proves it (from the `spec` report); the non-trivial decisions from dev's summary; anything left for the human reviewer.
    4. **Watch the PR** with a blocking wait, not a polling skill (the `loop` skill doesn't survive an unattended `claude -p` run, which ends when the turn ends):
        - Wait ~30 s after the push, then run `timeout 540 gh pr checks <n> --watch --interval 30`. If it exits because of the timeout, run it again; if it says no checks are reported yet, wait 30 s and retry, up to 5 times.
        - When checks are done, read the automated review: the PR conversation with `gh api repos/{owner}/{repo}/issues/<n>/comments` and the inline comments with `gh api repos/{owner}/{repo}/pulls/<n>/comments`. Run each as one command, with no `;`, `&&`, pipe or flag (no `--jq`), or the permission rules won't match. Consider only comments whose author (`user.login`) is the automated review bot (`claude` / `claude[bot]`) and ignore every other author's comments; filter by reading the output, not with a command option. Don't use `gh pr view <n> --comments` (GraphQL, fails on the bot token's scopes).
        - CI green and no blocking review findings: stop and tell the user the PR is ready for their review — this is the point you come back to the human (status `pr_ready` in an unattended run).
        - CI failure or a blocking review finding: diagnose from the actual logs (`gh run view <run-id> --log-failed`), never guess at what broke. Dispatch the right agent (`dev` for code bugs, `quality` for lint/static-analysis/security), commit, push, watch again.
        - The same failure persists past 2 fix-rounds, or a _different_ failure keeps appearing round after round for 4 total, or it looks like a local/environment issue rather than something a repo-code fix addresses: stop and message the user directly with the diagnosis instead of retrying blindly (status `escalated` in an unattended run).

## Important

- Never merge the PR. That's always an explicit user action on GitHub, never part of this chain.
- If a genuinely manual, step-by-step walkthrough is ever wanted instead (e.g. picking back up after this chain escalated to the user), `/cto`'s wrap-up mode still gives the exact git sequence on request.
