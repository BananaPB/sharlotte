---
description: Creates a feature branch and launches the Dev agent to implement it
argument-hint: <short feature description>
---

You are orchestrating the start of the multi-agent workflow for a new feature: **$ARGUMENTS**

## Steps to follow, in order

1. Verify the working tree is clean (`git status`). If it isn't, stop and ask the user how to proceed — never stash/commit anything without confirmation.
2. Determine a short, explicit branch name from the description (`feature/<short-name>`), propose it, and create it: `git checkout -b feature/<short-name>`.
3. Invoke the **dev** sub-agent (see `.claude/agents/dev.md`) with the full feature description, explicitly reminding it to respect `CLAUDE.md`.
4. Once the dev agent is done, display a clear summary for the human:
   - List of created/modified files (`git status --short`).
   - Non-trivial technical decisions made by the dev agent.
   - Explicit suggestion for next steps: run `/audit` then `/test` before opening the PR.

## Important

- You do NOT run linters, static analysis, or tests yourself at this stage — that's the role of `/audit` and `/test`.
- You make NO automatic commit. Let the user review the diff and commit themselves, or commit only on explicit request with a clear message.
- Stay concise in your intermediate messages: the user wants the result, not a narration of every step.
