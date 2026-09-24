---
description: Creates a feature branch and launches the Dev agent to implement it
argument-hint: <short feature description>
---

You are orchestrating the start of the multi-agent workflow for a new feature: **$ARGUMENTS**

## Steps to follow, in order

1. Verify the working tree is clean (`git status`). If it isn't, stop and ask the user how to proceed — never stash/commit anything without confirmation.
2. Determine a short, explicit branch name from the description (`feature/<short-name>`), propose it, and create it: `git checkout -b feature/<short-name>`.
3. Invoke the **dev** sub-agent (see `.claude/agents/dev.md`) with the full feature description, explicitly reminding it to respect `CLAUDE.md`.
4. Once the dev agent is done, display a brief summary for the human:
    - List of created/modified files (`git status --short`).
    - Non-trivial technical decisions made by the dev agent.
5. Continue straight into the audit phase described in `/audit` — don't wait for the user to invoke it separately. This is a standing authorization (`docs/decisions.md`, entry 7, 2026-09-24): the human checkpoints are agreeing on what to build (already done — that's this command's `$ARGUMENTS`) and reviewing the opened PR before merge, not every intermediate step.

## Important

- You do NOT run linters, static analysis, or tests yourself at this stage — that's the role of `/audit` and `/test`.
- You make NO commit at this point — that happens once `/test` passes, per its own instructions.
- If dev's own summary flags something needing a real human call (a genuine design ambiguity, not just "here's what I built"), stop and ask instead of continuing into `/audit`.
- Stay concise in your intermediate messages: the user wants the result and the final "ready for review" signal, not a narration of every step.
