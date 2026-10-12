#!/usr/bin/env bash
# Runs INSIDE the dev container (started by nightshift.sh). Prepares a fresh clone of the repo in
# /workspace, then starts one unattended Claude Code run. Everything it prints on stdout is the
# Claude Code JSON result; setup noise goes to stderr.
#
# Usage: in-container.sh feature <issue-number>
#        in-container.sh doc <merged-pr-number>
#        in-container.sh audit <output-dir-name>
#        in-container.sh cto <message-file>        (CTO_SESSION=<id> to continue a conversation)
set -euo pipefail

mode="${1:?mode required}"
arg="${2:-}"

# --- git identity and credentials: the bot account, never the human's ------------------------
# The token only lives in the environment; the credential helper hands it to git on push.
git config --global user.name "${BOT_NAME:?BOT_NAME not set}"
git config --global user.email "${BOT_EMAIL:?BOT_EMAIL not set}"
git config --global credential.helper \
    '!f() { echo "username=x-access-token"; echo "password=${GH_TOKEN}"; }; f'

# --- fresh clone of main, then the same setup a developer gets in VS Code ---------------------
cd /workspace
if [ ! -d .git ]; then
    git clone --quiet "https://github.com/${REPO_SLUG:?REPO_SLUG not set}.git" . >&2
fi
git fetch --quiet origin >&2
git checkout --quiet -B main origin/main >&2
# The CTO only reads and writes docs: skip the PHP/Node setup to keep each Telegram reply fast.
if [ "$mode" != "cto" ]; then
    bash .devcontainer/post-create.sh >&2
fi

# --- trust /workspace --------------------------------------------------------------------------
# Without this, Claude Code ignores the project's permissions.allow ("workspace has not been
# trusted") and `--permission-prompts none` then denies every command. Every mode runs in /workspace.
# jq is installed in the dev container image; the existing file's other keys are kept.
claude_json="$HOME/.claude.json"
[ -s "$claude_json" ] || echo '{}' > "$claude_json"
jq '.projects["/workspace"].hasTrustDialogAccepted = true' "$claude_json" > "$claude_json.tmp"
mv "$claude_json.tmp" "$claude_json"
chmod 600 "$claude_json" # it normally holds account data; the new file would get the default umask

common_flags=(
    --settings .claude/settings.headless.json
    --permission-mode acceptEdits
    --permission-prompts none
    --append-system-prompt-file /nightshift/unattended.md
)
if [ -n "${CLAUDE_MODEL:-}" ]; then
    common_flags+=(--model "$CLAUDE_MODEL")
fi
schema="$(cat /nightshift/result.schema.json)"

case "$mode" in
feature)
    exec claude -p "/feature #${arg}" "${common_flags[@]}" \
        --max-turns "${MAX_TURNS:-300}" \
        --output-format json --json-schema "$schema"
    ;;
doc)
    exec claude -p "/doc — documenting up to merged PR #${arg}" "${common_flags[@]}" \
        --max-turns 80 \
        --output-format json --json-schema "$schema"
    ;;
audit)
    # The security-audit skill is mounted at ~/.claude/skills/security-audit by nightshift.sh.
    exec claude -p "Run a full security audit of this codebase with the security-audit skill, in full audit mode. Write every output file to /audits/${arg}. Do not modify the repository. When done, summarise the confirmed findings by severity." \
        "${common_flags[@]}" --add-dir /audits \
        --max-turns 600 \
        --output-format json
    ;;
cto)
    message="$(cat "/nightshift-state/${arg}")"
    resume=()
    if [ -n "${CTO_SESSION:-}" ]; then resume=(--resume "$CTO_SESSION"); fi
    # A CTO conversation is not an unattended run: it asks questions and waits for the answers.
    unset NIGHTSHIFT
    # The CTO only reads, creates nightshift issues, and records agreed decisions through a PR.
    CTO_RELAY=1 exec claude -p "/cto ${message}" "${resume[@]}" \
        --permission-mode default --permission-prompts none \
        --allowedTools "Read" "Grep" "Glob" \
            "Bash(git log *)" "Bash(git fetch *)" "Bash(git checkout -b docs/decision-*)" \
            "Edit(docs/decisions.md)" "Bash(git add docs/decisions.md)" "Bash(git commit *)" \
            "Bash(git push -u origin docs/decision-*)" "Bash(gh pr create *)" \
            "Bash(gh issue create *)" "Bash(gh issue list *)" "Bash(gh issue view *)" \
            "Skill" \
        --max-turns 60 \
        --output-format json
    ;;
*)
    echo "unknown mode: $mode" >&2
    exit 2
    ;;
esac
