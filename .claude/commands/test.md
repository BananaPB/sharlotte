---
description: Launches the QA/Test agent to generate and run Pest & Vitest tests on the current diff
---

Invoke the **qa** sub-agent (see `.claude/agents/qa.md`) on the active branch's current `git diff`.

## Steps

1. Verify we're actually on a feature branch (not `main`).
2. Invoke the `qa` sub-agent with the diff between `main` and the current branch as its scope.
3. Display the full report: tests added, Pest/Vitest results, and any failure linked to a code bug.

## Then

This is a standing authorization to chain all the way through to an open, watched PR without waiting for the user at each step (`docs/decisions.md`, entry 7, 2026-09-24) — the human checkpoints are what to build and the final PR review, not every intermediate step. **Merging is never part of this chain — that's always an explicit user action on GitHub.**

- **Tests fail on a real code bug** (not a test-writing issue): dispatch `dev` directly with the specific failure, then re-run this test step on the updated code. Cap it at 2 fix-rounds on the same failure, or 4 total fix-rounds on this step overall — whichever comes first, so a fix that introduces a *different* new failure each round doesn't dodge the cap by never repeating the same one. Past that: stop and report to the user directly instead of retrying.
- **Tests pass**: commit, push, open the PR, and watch it through to green:
    1. Skip the local `npm run check:fix` pre-check — this session runs in Git Bash, a confirmed dead end for it (`docs/git-github-workflow.md` §9.4). Rely on CI's own formatting gate instead; if it flags something, treat it as a normal CI failure in step 4 below.
    2. `git status --short` for the real file list. Group into logical, atomic commits — never `git add -A`/`git add .` (this session's own git safety protocol, not a CLAUDE.md rule) — with `type(scope): description` messages (CLAUDE.md §7).
    3. `git push -u origin <branch>` (plain `git push` if already tracking). Then `gh pr create` with a title/body describing what changed and why — unless this branch already has an open PR, in which case the push alone updates it; never open a second PR for the same branch.
    4. Watch the PR: invoke the `loop` skill yourself (don't wait for the user to) to poll `gh pr checks <n>` and the automated PR review's comments (`gh api repos/<owner>/<repo>/issues/<pr>/comments`) on an interval matched to how fast they actually resolve here — roughly every 3–5 minutes, not constantly. Read real results (`gh run view --log-failed` on a failure), never assume or guess at what broke.
        - CI green and no blocking review findings: stop the loop and tell the user the PR is ready for their review — this is the point you come back to the human.
        - CI failure or a blocking review finding: diagnose from the actual logs, dispatch the right agent (`dev` for code bugs, `quality` for lint/static-analysis/security), commit, push, keep watching.
        - The same failure persists past 2 fix-rounds, or a *different* failure keeps appearing round after round for 4 total, or it looks like a local/environment issue rather than something a repo-code fix addresses: stop looping and message the user directly with the diagnosis instead of retrying blindly.

## Important

- Never merge the PR. That's always an explicit user action on GitHub, never part of this chain.
- If a genuinely manual, step-by-step walkthrough is ever wanted instead (e.g. picking back up after this chain escalated to the user), `/cto`'s wrap-up mode still gives the exact git sequence on request.
