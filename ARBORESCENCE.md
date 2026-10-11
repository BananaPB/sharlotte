# Project layout (Claude Code-related files)

```
sharlotte/
├── .claude/
│   ├── agents/                     # Specialized sub-agents (separated roles)
│   │   ├── dev.md                  # Dev agent — builds test-first at the brief's seams
│   │   ├── quality.md              # Quality & Security agent — diff audit, linters
│   │   ├── spec.md                 # Spec reviewer — does the diff do what the brief asked?
│   │   ├── qa.md                   # QA/Test agent — tries to break the feature from its brief
│   │   └── doc.md                  # Documentation agent — docs & changelog
│   ├── commands/                   # Slash commands (orchestration)
│   │   ├── cto.md                  # /cto — decisions, and nightshift planning
│   │   ├── feature.md              # /feature — creates a branch + launches the Dev agent
│   │   ├── audit.md                # /audit — launches the Quality & Spec agents in parallel
│   │   ├── test.md                 # /test — launches the QA/Test agent
│   │   └── doc.md                  # /doc — launches the Documentation agent (post-merge)
│   ├── skills/
│   │   ├── tdd/                    # Red → green loop (adapted from mattpocock/skills), preloaded by dev
│   │   └── grilling/               # Question rounds until a brief is settled (mattpocock/skills), used by /cto
│   ├── settings.json               # Permissions & default model
│   └── settings.headless.json      # Extra permissions for unattended (nightshift) runs
├── .devcontainer/                  # One Linux toolchain for VS Code and nightshift (PHP 8.5, Node 22, Postgres 17)
├── scripts/nightshift/             # Unattended runner started by Hermes — see docs/nightshift.md
├── .github/
│   ├── CODEOWNERS                  # Owner review required on main (the bot can't approve its own work)
│   ├── ISSUE_TEMPLATE/
│   │   └── feature-brief.md        # The brief format every feature starts from
│   └── workflows/
│       └── ai-pr-review.yml        # Remote PR Reviewer agent (GitHub Actions)
├── docs/
│   ├── nightshift.md               # Setup & daily use of unattended runs
│   ├── architecture.md             # Technical doc kept up to date by the Documentation agent
│   └── changelog.md                # User-facing changelog kept up to date by the Documentation agent
├── CLAUDE.md                       # Global directives read by all agents
├── .claudeignore                   # Files/folders excluded from context
├── app/                            # Laravel (existing)
├── resources/js/                   # React + Inertia (existing)
├── tests/                          # Pest (existing)
└── ...
```

## Why sub-agents (`.claude/agents/`) rather than roles "in Claude's head"?

A Claude Code sub-agent has its **own context**, its **own system prompt**, and can have a **restricted set of tools** (`tools:` in the frontmatter). This is the native mechanism that maps exactly to the request for "clearly separated roles to avoid overloading requests":

- The **Dev** agent doesn't need to see the raw output of `pest`/`phpstan`.
- The **Quality & Security** agent doesn't need to write new features, only to read the diff and run linters.
- The **QA/Test** agent only needs to write/run tests.
- The **Documentation** agent only touches `docs/`.

The slash commands (`/feature`, `/audit`, `/test`, `/doc`) are the orchestration layer: they describe **when** and **how** to invoke each sub-agent, in what order, with what stop conditions (e.g. don't move on to the QA agent if the Quality agent found a blocking flaw).
