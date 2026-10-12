---
description: Launches the Documentation agent to update docs/architecture.md, docs/changelog.md, the roadmap's "Current state" and README.md after a merge to main
---

Invoke the **doc** sub-agent (see `.claude/agents/doc.md`).

## Steps

1. Verify we're on an up-to-date `main`: `git checkout main && git pull`.
2. Invoke the `doc` sub-agent, giving it the range of commits to document (from the last `docs/changelog.md` update, visible in its git history, up to `HEAD`).
3. Display a diff of the proposed changes to `docs/architecture.md`, `docs/changelog.md`, the "Current state" section of `docs/roadmap.md` and `README.md`.
4. Then, depending on how this command was started:
    - **Interactive session**: only commit on explicit user confirmation (then a `docs/post-pr<N>-update` branch and a PR, like every other change).
    - **Unattended run** (`NIGHTSHIFT=1`, started by Hermes after a merge): there is nobody to confirm, so the PR review is the confirmation. Create `docs/post-pr<N>-update` from `main` (`<N>` = the merged PR number you were given), commit only the files that the agent changed (under `docs/`, plus `README.md`), push, and open a PR titled `docs: update after #<N>`. End with status `pr_ready` (`CLAUDE.md` §10). If the agent reports that a merged decision isn't in `docs/decisions.md`, put that line in the PR body for the CTO — don't write it yourself.

## Important

- Never push to `main` directly and never merge the docs PR — same rule as every other branch.
- This command is not meant for CI.
