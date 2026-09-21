---
description: Launches the QA/Test agent to generate and run Pest & Vitest tests on the current diff
---

Invoke the **qa** sub-agent (see `.claude/agents/qa.md`) on the active branch's current `git diff`.

## Steps

1. Verify we're actually on a feature branch (not `main`).
2. Invoke the `qa` sub-agent with the diff between `main` and the current branch as its scope.
3. Display the full report: tests added, Pest/Vitest results, and any failure linked to a code bug.
4. If tests fail because of a code bug (not a poorly written test), clearly summarize the problem and propose re-launching the `dev` agent to fix it, pending user confirmation.
5. If everything passes, end with: "Tests OK — run `/cto` to get the exact git add/commit/push/PR sequence for this branch."

## Important

- Never open the PR automatically. That's an explicit user action.
