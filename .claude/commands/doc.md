---
description: Launches the Documentation agent to update docs/architecture.md and docs/changelog.md after a merge to main
---

Invoke the **doc** sub-agent (see `.claude/agents/doc.md`).

## Steps

1. Verify we're actually on `main` and up to date: `git checkout main && git pull`.
2. Invoke the `doc` sub-agent, giving it the range of commits to document (from the last `docs/changelog.md` update, visible in its git history, up to `HEAD`).
3. Display a diff of the proposed changes to `docs/architecture.md` and `docs/changelog.md` before any commit.
4. Only commit on explicit user confirmation.

## Important

- This command is intended for **local, post-merge** use — not in CI.
