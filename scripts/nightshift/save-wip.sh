#!/usr/bin/env bash
# Saves work that exists only in the throwaway container: run from /workspace after a feature run.
# Usage: bash /nightshift/save-wip.sh <issue-number>
#
# Does nothing on main, or when the tree is clean and HEAD is already on a remote branch.
# Otherwise commits everything not ignored and pushes HEAD to a NEW branch nightshift-wip/<n>-<UTC time>.
# Never pushes anywhere else, never forces, never deletes, never fails the caller (always exit 0),
# and prints only to stderr.
set -u

n="${1:-}"

# Everything below must go to stderr; this wrapper guarantees stdout stays empty.
main() {
    [ -n "$n" ] || { echo "save-wip: issue number missing" >&2; return 0; }
    # Digits only: the number ends up in a branch name and in gh's arguments.
    [[ "$n" =~ ^[0-9]+$ ]] || { echo "save-wip: issue number must be digits, got '$n'" >&2; return 0; }

    local branch
    branch="$(git rev-parse --abbrev-ref HEAD)" || { echo "save-wip: cannot read current branch" >&2; return 0; }
    [ "$branch" != "main" ] || return 0

    local dirty remotes
    dirty="$(git status --porcelain)" || { echo "save-wip: git status failed" >&2; return 0; }
    remotes="$(git branch -r --contains HEAD 2>/dev/null)"
    if [ -z "$dirty" ] && [ -n "$remotes" ]; then
        return 0
    fi

    git add -A || { echo "save-wip: git add failed" >&2; return 0; }
    # The branch is public and a pushed secret cannot be taken back: unstage anything that looks
    # like one, on top of .gitignore (which only covers .env, auth.json and a few more).
    local f
    while IFS= read -r -d '' f; do
        case "$f" in
            .env.example | */.env.example) ;;
            .env* | */.env* | *.pem | *.key | *.p12 | auth.json | */auth.json | .claude/settings.local.json)
                git reset -q -- "$f" && echo "save-wip: left out $f" >&2 ;;
        esac
    done < <(git diff --cached --name-only -z)
    # Hooks are disabled: the agent could have planted one, and it would run with the bot token.
    if ! git diff --cached --quiet; then
        git -c core.hooksPath=/dev/null commit --quiet --no-verify -m "wip(nightshift): unfinished work on #$n" \
            || { echo "save-wip: git commit failed" >&2; return 0; }
    fi

    local wip="nightshift-wip/$n-$(date -u +%Y%m%d%H%M%S)"
    git -c core.hooksPath=/dev/null push --quiet --no-verify origin "HEAD:refs/heads/$wip" \
        || { echo "save-wip: git push to $wip failed" >&2; return 0; }
    echo "save-wip: pushed $wip" >&2

    gh issue comment "$n" -R "${REPO_SLUG:-}" \
        --body "\`$wip\`: Work in progress saved here; check it out and continue with /audit and /test, or discard it." \
        >&2 || echo "save-wip: gh issue comment failed" >&2
    return 0
}

main >&2 || true
exit 0
