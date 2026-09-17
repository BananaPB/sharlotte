# Project layout (Claude Code-related files)

```
sharlotte/
├── .claude/
│   ├── agents/                     # Specialized sub-agents (separated roles)
│   │   ├── dev.md                  # Dev agent — code generation
│   │   ├── quality.md              # Quality & Security agent — diff audit
│   │   ├── qa.md                   # QA/Test agent — Pest & Vitest
│   │   └── doc.md                  # Documentation agent — docs & changelog
│   ├── commands/                   # Slash commands (orchestration)
│   │   ├── feature.md              # /feature — creates a branch + launches the Dev agent
│   │   ├── audit.md                # /audit — launches the Quality & Security agent
│   │   ├── test.md                 # /test — launches the QA/Test agent
│   │   └── doc.md                  # /doc — launches the Documentation agent (post-merge)
│   └── settings.json               # Permissions & default model (optional, see guide)
├── .github/
│   └── workflows/
│       └── ai-pr-review.yml        # Remote PR Reviewer agent (GitHub Actions)
├── docs/
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
