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

## Important

- Don't automatically chain into `/test` — it's up to the user to decide, especially if critical issues were raised.
