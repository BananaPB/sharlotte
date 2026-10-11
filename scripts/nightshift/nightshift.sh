#!/usr/bin/env bash
# nightshift — runs queued feature briefs unattended, one at a time, each in a fresh container.
#
# This is deliberately a plain script, not an agent: the guarantees that matter (one feature at a
# time, a fresh clone of main per feature, a cap on how many features and until what time, never a
# merge) are enforced here in code, not left to a model's judgement. See docs/nightshift.md.
#
# Usage: nightshift.sh <command>
#   labels          create the nightshift:* labels on the repo (once)
#   start           start a run in the background and return immediately (what Hermes calls)
#   run             process the queue in the foreground
#   watch           print alerts since the last call (escalations, failures, limits); silent if none
#   report          print the latest night's report once; silent if already reported
#   docs            if PRs were merged since the last docs run, start /doc in the background
#   audit           start a full security audit in the background (security-audit skill)
#   cto "<msg>"|-   relay one message (or stdin) to the /cto session and print its reply
#   cto-new         forget the current /cto session (next message starts a new one)
#   install-hermes  write the wrapper scripts Hermes' cron jobs call (see cmd_install_hermes)
#   ssh-dispatch    SSH forced command for a containerized Hermes (see cmd_ssh_dispatch)
set -euo pipefail

SELF="$(readlink -f "$0")"
ENV_FILE="${NIGHTSHIFT_ENV:-$HOME/.config/nightshift/env}"
[ -f "$ENV_FILE" ] || { echo "nightshift: missing config $ENV_FILE (copy scripts/nightshift/env.example)" >&2; exit 1; }
# shellcheck source=/dev/null
source "$ENV_FILE"

: "${REPO_SLUG:?}" "${REPO_DIR:?}" "${STATE_DIR:?}" "${GH_TOKEN:?}" "${CLAUDE_CODE_OAUTH_TOKEN:?}"
: "${BOT_NAME:?}" "${BOT_EMAIL:?}" "${TRUSTED_AUTHORS:?}"
MAX_FEATURES="${MAX_FEATURES:-3}"
STOP_LAUNCHING_AT="${STOP_LAUNCHING_AT:-06:00}"
FEATURE_TIMEOUT="${FEATURE_TIMEOUT:-4h}"
MAX_TURNS="${MAX_TURNS:-300}"
export GH_TOKEN CLAUDE_CODE_OAUTH_TOKEN REPO_SLUG BOT_NAME BOT_EMAIL MAX_TURNS
export CLAUDE_MODEL="${CLAUDE_MODEL:-}"
export USER_UID="$(id -u)" USER_GID="$(id -g)"

mkdir -p "$STATE_DIR"/{nights,cto,audits,docs}
EVENTS="$STATE_DIR/events.tsv"
touch "$EVENTS"

# One line, no tabs or \x1f: for text the model wrote that ends up in a one-record-per-line file.
flatten() { printf '%s' "$1" | tr '\n\r\t\037' '    '; }

log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >&2; }

# One line per event, read back by `watch`: <time> <type> <text>
event() { printf '%s\t%s\t%s\n' "$(date '+%F %H:%M')" "$1" "$2" >> "$EVENTS"; }

# Run one mode of in-container.sh in a throwaway container of the given compose project.
#   in_container <project> <workdir> <mode> <arg> [extra `docker compose run` args...]
# With RUN_TIMEOUT set (e.g. 4h), the container is killed if it runs longer.
in_container() {
    local project="$1" workdir="$2" mode="$3" arg="$4" name rc=0
    shift 4
    name="$project-$mode-$(date +%s)"
    local -a limit=()
    if [ -n "${RUN_TIMEOUT:-}" ]; then limit=(timeout "$RUN_TIMEOUT"); fi
    WORKSPACE_DIR="$workdir" NIGHTSHIFT=1 \
        "${limit[@]}" docker compose -p "$project" -f "$REPO_DIR/.devcontainer/compose.yaml" \
        run --rm -T --name "$name" \
        -v "$REPO_DIR/scripts/nightshift:/nightshift:ro" \
        -e REPO_SLUG -e BOT_NAME -e BOT_EMAIL -e MAX_TURNS -e CLAUDE_MODEL \
        "$@" \
        app bash /nightshift/in-container.sh "$mode" "$arg" || rc=$?
    # A timeout kills the compose client, not necessarily the container: make sure it's gone.
    docker rm -f "$name" >/dev/null 2>&1 || true
    return "$rc"
}

update_runner_clone() {
    if [ ! -d "$REPO_DIR/.git" ]; then
        git clone --quiet "https://github.com/$REPO_SLUG.git" "$REPO_DIR"
    fi
    git -C "$REPO_DIR" fetch --quiet origin
    git -C "$REPO_DIR" checkout --quiet -B main origin/main
}

# Issue numbers labelled nightshift:ready, opened by a trusted author, oldest first.
# Anyone can open an issue on a public repo; only collaborators can label one, and only trusted
# authors' issue bodies are ever handed to an agent.
queued_issues() {
    gh issue list -R "$REPO_SLUG" --label "nightshift:ready" --state open --limit 50 \
        --json number,author,createdAt |
        jq -r --arg trusted "$TRUSTED_AUTHORS" '
            ($trusted | split(" ")) as $t
            | sort_by(.createdAt)[]
            | select(.author.login as $a | $t | index($a))
            | .number'
}

set_label() { # <issue> <remove> <add>
    gh issue edit "$1" -R "$REPO_SLUG" --remove-label "$2" --add-label "$3" >/dev/null || true
}

# --- commands -----------------------------------------------------------------------------------

cmd_labels() {
    local l
    for l in ready running pr-ready escalated failed; do
        gh label create "nightshift:$l" -R "$REPO_SLUG" --force --color 5319e7 \
            --description "nightshift queue: $l" >/dev/null
    done
    echo "labels ok"
}

cmd_start() {
    local n
    n="$(queued_issues | wc -l)"
    [ "$n" -gt 0 ] || exit 0 # nothing queued: stay silent
    setsid nohup "$SELF" run >> "$STATE_DIR/run.log" 2>&1 < /dev/null &
    echo "Nightshift lancé : $n brief(s) en file, $MAX_FEATURES max, pas de nouveau lancement après $STOP_LAUNCHING_AT."
}

cmd_run() {
    exec 9> "$STATE_DIR/run.lock"
    flock -n 9 || { log "a run is already in progress"; exit 0; }

    update_runner_clone
    local issues
    issues="$(queued_issues)"
    [ -n "$issues" ] || { log "queue empty"; exit 0; }

    local night dir deadline
    night="$(date +%F_%H%M)"
    dir="$STATE_DIR/nights/$night"
    mkdir -p "$dir"
    deadline="$(date -d "today $STOP_LAUNCHING_AT" +%s)"
    [ "$deadline" -gt "$(date +%s)" ] || deadline="$(date -d "tomorrow $STOP_LAUNCHING_AT" +%s)"

    log "night $night: queue = $(echo "$issues" | tr '\n' ' ')"
    docker compose -p nightshift -f "$REPO_DIR/.devcontainer/compose.yaml" build --quiet app

    local count=0 n out status pr branch reason summary rc
    : > "$dir/results.tsv"
    for n in $issues; do
        if [ "$count" -ge "$MAX_FEATURES" ]; then break; fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            event info "Heure limite $STOP_LAUNCHING_AT atteinte, reste de la file pour la prochaine fois."
            break
        fi
        count=$((count + 1))
        set_label "$n" "nightshift:ready" "nightshift:running"
        mkdir -p "$dir/issue-$n"
        out="$dir/issue-$n.json"
        log "#$n: start"

        rc=0
        RUN_TIMEOUT="$FEATURE_TIMEOUT" in_container nightshift "$dir/issue-$n" feature "$n" \
            > "$out" 2> "$dir/issue-$n.log" || rc=$?
        # Throw the database away after each brief, so the next one starts from the same empty state
        # instead of inheriting this brief's tables and migration rows.
        docker compose -p nightshift -f "$REPO_DIR/.devcontainer/compose.yaml" down -v --remove-orphans >/dev/null 2>&1 || true

        status="$(jq -r '.structured_output.status // empty' "$out" 2>/dev/null || true)"
        pr="$(jq -r '.structured_output.pr_url // empty' "$out" 2>/dev/null || true)"
        branch="$(jq -r '.structured_output.branch // empty' "$out" 2>/dev/null || true)"
        reason="$(jq -r '.structured_output.reason // empty' "$out" 2>/dev/null || true)"
        summary="$(jq -r '.structured_output.summary // empty' "$out" 2>/dev/null || true)"
        # These fields are written by the model: one record per line in results.tsv, so no newlines,
        # tabs or separator characters may survive.
        reason="$(flatten "$reason")"
        pr="$(flatten "$pr")"
        branch="$(flatten "$branch")"
        if [ -z "$status" ]; then
            # Look for the usage limit only in Claude Code's own error result (or, if it produced no
            # JSON, the last lines of its output) — never in the whole log, which contains the brief.
            local error_text
            error_text="$(jq -r 'select(.is_error == true) | .result // empty' "$out" 2>/dev/null || true)"
            [ -s "$out" ] || error_text="$(tail -n 5 "$dir/issue-$n.log" 2>/dev/null || true)"
            if grep -qiE 'usage limit|rate.?limit|limit reached' <<< "$error_text"; then
                status="limit"
                reason="Limite d'usage Claude atteinte."
            elif [ "$rc" -eq 124 ]; then
                status="failed"
                reason="Arrêté après $FEATURE_TIMEOUT sans résultat."
            else
                status="failed"
                reason="Pas de résultat ($(jq -r '.subtype // "crash"' "$out" 2>/dev/null || echo crash), code $rc). Voir $dir/issue-$n.log"
            fi
        fi
        log "#$n: $status $pr"
        # \x1f (unit separator) rather than a tab: `read` would merge empty tab-separated fields.
        printf '%s\x1f%s\x1f%s\x1f%s\x1f%s\n' "$n" "$status" "$pr" "$branch" "$reason" >> "$dir/results.tsv"

        case "$status" in
        pr_ready) set_label "$n" "nightshift:running" "nightshift:pr-ready" ;;
        escalated) set_label "$n" "nightshift:running" "nightshift:escalated" ;;
        limit) set_label "$n" "nightshift:running" "nightshift:ready" ;; # requeued as-is
        *) set_label "$n" "nightshift:running" "nightshift:failed" ;;
        esac
        gh issue comment "$n" -R "$REPO_SLUG" --body "**Nightshift ($night)** — \`$status\`${pr:+ — $pr}

${reason:+**Raison :** $reason

}${summary}" >/dev/null || true

        case "$status" in
        escalated | failed) event "$status" "#$n : $reason${pr:+ ($pr)}" ;;
        limit)
            event limit "Limite d'usage atteinte pendant #$n, arrêt de la nuit. #$n est remise en file."
            break
            ;;
        esac
        # Code that reached a PR is safe on GitHub; keep the work dir only when there's something to inspect.
        if [ "$status" = "pr_ready" ]; then rm -rf "$dir/issue-$n"; fi
    done

    # Every PR of the night started from the same main: check they don't conflict with each other.
    local -a branches=()
    mapfile -t branches < <(awk -F'\x1f' '$2 == "pr_ready" && $4 != "" { print $4 }' "$dir/results.tsv")
    : > "$dir/conflicts.txt"
    if [ "${#branches[@]}" -gt 1 ]; then
        git -C "$REPO_DIR" fetch --quiet origin
        local i j
        for ((i = 0; i < ${#branches[@]}; i++)); do
            for ((j = i + 1; j < ${#branches[@]}; j++)); do
                if ! git -C "$REPO_DIR" merge-tree --write-tree --name-only \
                    "origin/${branches[i]}" "origin/${branches[j]}" > /dev/null 2>&1; then
                    echo "${branches[i]} ↔ ${branches[j]}" >> "$dir/conflicts.txt"
                fi
            done
        done
    fi

    docker compose -p nightshift -f "$REPO_DIR/.devcontainer/compose.yaml" down -v --remove-orphans >/dev/null 2>&1 || true
    write_report "$dir" "$night"
    event night_done "Nuit $night terminée, rapport prêt."
}

write_report() { # <dir> <night>
    local dir="$1" night="$2" line n status pr branch reason
    {
        echo "Nightshift $night"
        echo
        while IFS=$'\x1f' read -r n status pr branch reason; do
            case "$status" in
            pr_ready) echo "✅ #$n prête à relire : $pr" ;;
            escalated) echo "✋ #$n a besoin de toi : $reason${pr:+ ($pr)}" ;;
            limit) echo "⏸ #$n remise en file (limite d'usage)" ;;
            *) echo "❌ #$n échec : $reason" ;;
            esac
        done < "$dir/results.tsv"
        echo
        if [ -s "$dir/conflicts.txt" ]; then
            echo "⚠️ Conflits entre PR de la nuit (merger l'une, puis mettre l'autre à jour) :"
            sed 's/^/  /' "$dir/conflicts.txt"
        else
            echo "Aucun conflit entre les PR de la nuit."
        fi
    } > "$dir/report.md"
}

cmd_watch() {
    # Single caller assumed (one Hermes cron job): two overlapping calls could lose or repeat an alert.
    local offset_file="$STATE_DIR/watch.offset" offset total
    offset="$(cat "$offset_file" 2>/dev/null || echo 0)"
    total="$(wc -l < "$EVENTS")"
    echo "$total" > "$offset_file"
    tail -n +"$((offset + 1))" "$EVENTS" | head -n "$((total - offset))" |
        awk -F'\t' '$2 ~ /^(escalated|failed|limit|error|docs|audit)$/ { print $1 " · " $3 }'
}

cmd_report() {
    local latest
    latest="$(ls -1d "$STATE_DIR"/nights/*/ 2>/dev/null | sort | tail -n 1)"
    [ -n "$latest" ] && [ -f "$latest/report.md" ] && [ ! -f "$latest/.reported" ] || exit 0
    cat "$latest/report.md"
    touch "$latest/.reported"
}

cmd_docs() {
    if [ "${1:-}" != "--foreground" ]; then
        setsid nohup "$SELF" docs --foreground >> "$STATE_DIR/docs.log" 2>&1 < /dev/null &
        exit 0
    fi
    exec 8> "$STATE_DIR/docs.lock"
    flock -n 8 || exit 0
    local since_file="$STATE_DIR/docs/last-run" since latest out
    since="$(cat "$since_file" 2>/dev/null || date -u -d '7 days ago' +%FT%TZ)"
    # Merged PRs since the last docs run, ignoring the docs PRs themselves.
    latest="$(gh pr list -R "$REPO_SLUG" --state merged --base main --limit 50 \
        --search "merged:>$since" --json number,headRefName |
        jq -r '[.[] | select(.headRefName | startswith("docs/") | not) | .number] | max // empty')"
    [ -n "$latest" ] || exit 0
    update_runner_clone
    local workdir="$STATE_DIR/docs/work-$latest"
    mkdir -p "$workdir"
    out="$STATE_DIR/docs/pr-$latest.json"
    in_container nightshift-docs "$workdir" doc "$latest" > "$out" 2> "$STATE_DIR/docs/pr-$latest.log" || true
    docker compose -p nightshift-docs -f "$REPO_DIR/.devcontainer/compose.yaml" down -v >/dev/null 2>&1 || true
    date -u +%FT%TZ > "$since_file" # UTC: GitHub's merged:> search reads it as UTC
    rm -rf "$workdir"
    event docs "Doc après #$latest : $(jq -r '.structured_output.status // "failed"' "$out" 2>/dev/null) $(jq -r '.structured_output.pr_url // .structured_output.reason // empty' "$out" 2>/dev/null)"
}

cmd_audit() {
    if [ "${1:-}" != "--foreground" ]; then
        setsid nohup "$SELF" audit --foreground >> "$STATE_DIR/audit.log" 2>&1 < /dev/null &
        echo "Audit de sécurité lancé (plusieurs heures)."
        exit 0
    fi
    : "${AUDIT_SKILL_DIR:?set AUDIT_SKILL_DIR to the cloned security-audit skill folder}"
    exec 7> "$STATE_DIR/audit.lock"
    flock -n 7 || exit 0
    update_runner_clone
    local run workdir out
    run="run-$(date +%F_%H%M)"
    workdir="$STATE_DIR/audits/work-$run"
    mkdir -p "$workdir" "$STATE_DIR/audits/$run"
    out="$STATE_DIR/audits/$run.json"
    in_container nightshift-audit "$workdir" audit "$run" \
        -v "$AUDIT_SKILL_DIR:/home/dev/.claude/skills/security-audit:ro" \
        -v "$STATE_DIR/audits:/audits" \
        > "$out" 2> "$STATE_DIR/audits/$run.log" || true
    docker compose -p nightshift-audit -f "$REPO_DIR/.devcontainer/compose.yaml" down -v >/dev/null 2>&1 || true
    rm -rf "$workdir"
    event audit "Audit $run terminé : $STATE_DIR/audits/$run/REPORT.md"
}

cmd_cto() {
    local message="${1:?message required (or - to read it from stdin)}" msgfile session out
    # Reading from stdin lets Hermes pass the message without shell-quoting it.
    if [ "$message" = "-" ]; then message="$(cat)"; fi
    msgfile="msg-$(date +%s%N).txt"
    printf '%s' "$message" > "$STATE_DIR/cto/$msgfile"
    session="$(cat "$STATE_DIR/cto/session" 2>/dev/null || true)"
    mkdir -p "$STATE_DIR/cto/workspace"
    update_runner_clone
    out="$(in_container nightshift-cto "$STATE_DIR/cto/workspace" cto "$msgfile" \
        -v "$STATE_DIR/cto:/nightshift-state:ro" -e CTO_SESSION="$session" 2>> "$STATE_DIR/cto/cto.log")" || true
    rm -f "$STATE_DIR/cto/$msgfile"
    jq -r '.session_id // empty' <<< "$out" > "$STATE_DIR/cto/session.new" 2>/dev/null || true
    if [ -s "$STATE_DIR/cto/session.new" ]; then mv "$STATE_DIR/cto/session.new" "$STATE_DIR/cto/session"; fi
    jq -r '.result // "Pas de réponse du CTO — voir cto.log."' <<< "$out" 2>/dev/null ||
        echo "Pas de réponse du CTO — voir $STATE_DIR/cto/cto.log."
}

cmd_cto_new() {
    rm -f "$STATE_DIR/cto/session"
    echo "Nouvelle conversation CTO."
}

# Writes the wrapper scripts Hermes' cron jobs call.
#   HERMES_SCRIPTS_DIR  where to write them (default: $HERMES_HOME/scripts, i.e. ~/.hermes/scripts)
#   NIGHTSHIFT_SSH      when Hermes runs in a container: the ssh command that reaches this host
#                       with the restricted key, e.g.
#                       "ssh -i /run/secrets/nightshift_key -o BatchMode=yes me@host.docker.internal"
#                       The wrappers then call `$NIGHTSHIFT_SSH <command>` (see `ssh-dispatch`).
cmd_install_hermes() {
    local dest="${HERMES_SCRIPTS_DIR:-${HERMES_HOME:-$HOME/.hermes}/scripts}" c
    mkdir -p "$dest"
    for c in start watch report docs audit; do
        if [ -n "${NIGHTSHIFT_SSH:-}" ]; then
            printf '#!/usr/bin/env bash\nexec %s %s\n' "$NIGHTSHIFT_SSH" "$c" > "$dest/nightshift-$c.sh"
        else
            printf '#!/usr/bin/env bash\nexec %q %s\n' "$SELF" "$c" > "$dest/nightshift-$c.sh"
        fi
        chmod +x "$dest/nightshift-$c.sh"
    done
    echo "wrappers written to $dest: nightshift-{start,watch,report,docs,audit}.sh"
}

# SSH forced command (authorized_keys: command="… nightshift.sh ssh-dispatch",restrict).
# Lets Hermes, running in a container, ask this host for nightshift commands — and nothing else:
# the key can't open a shell, forward ports, or run any command outside this list.
# A CTO message is always read from stdin, never from the command line, so it's never parsed by a shell.
cmd_ssh_dispatch() {
    case "${SSH_ORIGINAL_COMMAND:-}" in
    start | watch | report | docs | audit | cto-new) exec "$SELF" "$SSH_ORIGINAL_COMMAND" ;;
    cto) exec "$SELF" cto - ;;
    *)
        echo "nightshift: command not allowed: ${SSH_ORIGINAL_COMMAND:-<none>}" >&2
        exit 2
        ;;
    esac
}

case "${1:-}" in
labels) cmd_labels ;;
start) cmd_start ;;
run) cmd_run ;;
watch) cmd_watch ;;
report) cmd_report ;;
docs) shift; cmd_docs "$@" ;;
audit) shift; cmd_audit "$@" ;;
cto) shift; cmd_cto "$@" ;;
cto-new) cmd_cto_new ;;
install-hermes) cmd_install_hermes ;;
ssh-dispatch) cmd_ssh_dispatch ;;
*)
    sed -n '2,20p' "$SELF"
    exit 2
    ;;
esac
