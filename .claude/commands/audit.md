---
description: Launches the Quality & Security agent on the current diff (linters, static analysis, security)
---

Invoke the **quality** sub-agent (see `.claude/agents/quality.md`) on the active branch's current `git diff`.

## Steps

1. Verify we're actually on a feature branch (not `main`): `git branch --show-current`.
2. Invoke the `quality` sub-agent with the diff between `main` and the current branch as its scope.
3. Display its full report to the user, without summarizing or truncating it — security issues must be fully visible.
4. If **critical** (security) issues are detected, highlight them at the top of the message, before everything else.
5. End with a clear recommendation: "Ready for `/test`" or "Fixes needed before continuing — see above".

## Then

This is a standing authorization to chain forward without waiting for the user (`docs/decisions.md`, entry 7, 2026-09-24) — the human checkpoints are what to build and the final PR review, not every intermediate step:

- **CRITICAL security findings**: always stop here regardless of anything else below. Surface them clearly and wait for the user's explicit direction — never auto-fix-and-continue past a CRITICAL.
- **Other findings that need a fix and weren't auto-fixed** by `quality` itself: dispatch the right agent (usually `dev`) directly with the specific finding, then re-run this audit step on the updated diff. Cap it at 2 fix-rounds on the same finding — if it's still not clean after that, or the finding looks like something outside the repo (a local environment issue, or a genuine design call only the user should make), stop and report to the user directly instead of retrying.
- **Clean** (no findings, or everything `quality` fixed itself): continue straight into `/test` — no need to wait for the user to invoke it.
