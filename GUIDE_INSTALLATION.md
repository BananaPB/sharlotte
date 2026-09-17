# Installation guide — Claude Code multi-agent workflow

## 1. Install Claude Code locally

```bash
npm install -g @anthropic-ai/claude-code

# Check the version (sub-agents require a recent version)
claude --version
```

From your Laravel project's root folder:

```bash
cd my-saas
claude
```

On first run, sign in with your Claude account (Pro or Max subscription, as discussed).

## 2. Copy the workflow files into your project

Place the provided files following the layout in `ARBORESCENCE.md`:

```
my-saas/
├── CLAUDE.md
├── .claudeignore
├── .claude/
│   ├── settings.json
│   ├── agents/{dev,quality,qa,doc}.md
│   └── commands/{feature,audit,test,doc}.md
├── .github/workflows/ai-pr-review.yml
└── docs/{architecture,changelog}.md
```

Adjust the `[SaaS name]` title at the top of `CLAUDE.md`, and fill in section 1 (context) if needed.

## 3. Verify that Claude Code reads the config correctly

In a `claude` session at the project root:

```
/model
```

→ check that Sonnet is indeed selected by default (consistent with `.claude/settings.json`).

```
Summarize the strict rules from CLAUDE.md
```

→ if Claude correctly cites the 8 rules from section 3, the config is loaded properly.

## 4. Test the sub-agents individually

```
Use the quality sub-agent to audit the file app/Http/Controllers/Controller.php
```

If the sub-agent responds with the expected report format (Quality & Security Audit), the `.claude/agents/quality.md` configuration is working.

## 5. Test the full workflow on a small feature

```
/feature add an optional "internal notes" field to the Client model
```

Then, once the code has been generated and reviewed:

```
/audit
/test
```

Review each report before continuing — this is the human checkpoint.

## 6. Commit and open the PR

```bash
git add -A
git commit -m "feat(clients): add internal notes field"
git push -u origin feature/internal-notes
gh pr create --fill
```

(If `gh` is not installed: `brew install gh` or see https://cli.github.com/, then `gh auth login`.)

## 7. Set up the PR Reviewer agent (GitHub Actions)

### 7.1 Generate a Claude Code OAuth token for GitHub Actions

In a local terminal, with Claude Code installed and signed in to your subscription:

```bash
claude setup-token
```

This command generates an OAuth token tied to your Claude subscription, to be used in GitHub Actions (no separate API key needed initially).

### 7.2 Add the secret to GitHub

In the GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**:

- Name: `CLAUDE_CODE_OAUTH_TOKEN`
- Value: the token generated in the previous step

### 7.3 Verify

Open a test PR on GitHub. The `.github/workflows/ai-pr-review.yml` workflow should trigger automatically (visible in the **Actions** tab) and post a review comment on the PR within a few minutes.

## 8. Update the docs after a merge

Once the PR is merged to `main`, locally:

```bash
git checkout main
git pull
```

Then in Claude Code:

```
/doc
```

Review the proposed diff to `docs/architecture.md` and `docs/changelog.md` before confirming the commit.

## 9. Recommended working rhythm

```
/feature <description>   →  human review of the diff
/audit                    →  review of the report (blocking if critical)
/test                     →  review of the report
git add / commit / push
gh pr create               →  triggers the PR Reviewer agent on GitHub
[human review + AI review] →  manual merge
/doc                      →  (on main, after merge)
```

## 10. Cost note

With a Pro subscription, keep `/feature` sessions focused (one feature at a time, not a large global refactor) to avoid saturating your 5-hour quota. If you scale up your pace, see the move to Max mentioned earlier.
