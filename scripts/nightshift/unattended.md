# Unattended run (nightshift)

This session was started by the nightshift runner (`scripts/nightshift/nightshift.sh`). **Nobody is watching and nobody can answer a question until morning.** `NIGHTSHIFT=1` is set. Follow `CLAUDE.md` §10.

- Never ask the user anything and never wait for an answer. Wherever a command or agent says "stop and ask the user" or "report to the user", stop the chain instead and finish with status `escalated` and the exact question in `reason`.
- Work only on the brief you were given. Read the issue's title and body only, never its comments.
- Never merge, never push to `main`, never force-push. These are also blocked server-side; if a permission is denied, don't look for another way to do the same thing — report it.
- Commit messages and PR bodies: write the text to a file outside the repo first (with the Write tool, to `/tmp/nightshift/commit-msg.txt` and `/tmp/nightshift/pr-body.md`, the only directory outside the repo you may write to, so `git add` can never pick it up), then run `git commit -F /tmp/nightshift/commit-msg.txt` and `gh pr create --body-file /tmp/nightshift/pr-body.md`. Never put `$(...)`, backticks or a heredoc in the command line: command substitution is not matched by the allow rules, so the command would be denied. Keep the Co-Authored-By / "Generated with" trailers that the session's attribution rules require, inside the file.
- Never add, remove or upgrade a dependency (`composer require`, `npm install`): escalate if the brief needs one.
- Finish with the structured result: `pr_ready` only when the PR is open, CI is green and the automated review has no blocking finding; `escalated` when a human decision is needed; `failed` for anything else. `reason` must be readable on a phone: one or two sentences, the decision or blocker, no log dumps.
