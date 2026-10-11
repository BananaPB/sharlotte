---
description: Launches the Quality & Security agent and the Spec reviewer on the current diff (linters, static analysis, security, brief compliance)
---

Invoke the **quality** sub-agent (see `.claude/agents/quality.md`) and the **spec** sub-agent (see `.claude/agents/spec.md`) **in parallel** on the active branch's current `git diff`. They review two different things — how the code is written, and whether it does what the brief asked — and run separately so neither colours the other.

## Steps

1. Verify we're actually on a feature branch (not `main`): `git branch --show-current`.
2. Invoke both sub-agents at once, with the diff between `main` and the current branch as their scope. Give the `spec` agent the **brief verbatim** (the one `/feature` received). If this audit was started by hand and there is no brief in the session, ask the user for it or for the issue number; without one, run `quality` only and say the spec check was skipped.
3. Display both full reports to the user, without summarizing or truncating them — security issues must be fully visible.
4. If **critical** (security) issues are detected, highlight them at the top of the message, before everything else.
5. End with a clear recommendation: "Ready for `/test`" or "Fixes needed before continuing — see above".

## Then

This is a standing authorization to chain forward without waiting for the user (`docs/decisions.md`, entry 7, 2026-09-24) — the human checkpoints are what to build and the final PR review, not every intermediate step:

- **CRITICAL security findings**: always stop here regardless of anything else below. Surface them clearly and wait for the user's explicit direction — never auto-fix-and-continue past a CRITICAL. In an unattended run this means status `escalated` (`CLAUDE.md` §10).
- **A brief that the spec reviewer says is itself ambiguous**: stop and ask (interactive) or escalate (unattended). Looping `dev` on an ambiguous brief only guesses.
- **Other findings that need a fix and weren't auto-fixed** by `quality` itself — including `spec` findings (missing/partial criteria, blocking out-of-scope changes): dispatch the right agent (usually `dev`, with the brief and the specific finding) directly, then re-run this audit step on the updated diff. Cap it at 2 fix-rounds on the same finding, or 4 total fix-rounds on this step overall — whichever comes first, so a fix that introduces a _different_ new finding each round doesn't dodge the cap by never repeating the same one. Past that, or if a finding looks like something outside the repo (a local environment issue, or a genuine design call only the user should make): stop and report to the user directly instead of retrying (status `escalated` in an unattended run).
- **Clean** (no findings, or everything `quality` fixed itself, and `spec` says "Matches the brief"): continue straight into `/test` with the same brief — no need to wait for the user to invoke it.
