---
name: harness-creator
description: >-
  Build, audit, and improve harnesses that make AI coding agents reliable: AGENTS.md/CLAUDE.md
  instruction files, feature/state tracking, verification gates, scope boundaries, session
  handoff, memory persistence, context budgets, tool-permission safety, and multi-agent
  coordination. Use this whenever a coding agent is unreliable across sessions — forgets context,
  drifts out of scope, claims "done" before tests pass, or starts each session inconsistently —
  or when creating or assessing AGENTS.md, CLAUDE.md, feature_list.json, init.sh, progress.md, or
  session-handoff files. Reach for it even if the user never says the word "harness."
license: MIT
---

# Harness Creator

Use this skill to make a repository easier for coding agents to start, stay in scope, verify work, and resume across sessions. Keep the harness small enough that agents actually follow it.

Not for model selection, prompt tuning in isolation, chat UI design, or general app architecture.

## Core Model

Every useful coding-agent harness has five subsystems:

| Subsystem | Minimal artifact | Purpose |
|---|---|---|
| Instructions | `AGENTS.md` or `CLAUDE.md` | Startup path, working rules, definition of done |
| State | `feature_list.json`, `progress.md` | Current feature, status, evidence, next step |
| Verification | `init.sh` or documented commands | Tests/checks the agent must run before claiming done |
| Scope | Feature dependencies and done criteria | Prevents overreach and half-finished work |
| Lifecycle | `session-handoff.md`, end-of-session routine | Makes the next session restartable |
| Commit log | `.githooks/post-commit` → `.harness/commits/*.json` | Exactly what each commit did, per branch |

## First Move

1. Inspect what already exists: instruction files, feature/state files, verification commands, docs, package manifests.
2. Ask only for missing context that cannot be inferred safely: target agent, desired file name, tolerance for structure, and whether overwriting is allowed.
3. Prefer a minimal harness first. Add memory, tool safety, multi-agent, or benchmark details only when the user's problem calls for them.

## Common Tasks

### Create a harness

Use the bundled script when working on a local repository:

```bash
node skills/harness-creator/scripts/create-harness.mjs --target /path/to/project
```

Options:

- `--agent-file CLAUDE.md` for Claude-oriented projects.
- `--package-manager npm|pnpm|yarn|bun` when detection is wrong.
- `--commands "cmd one,cmd two"` for custom verification.
- `--force` only after confirming overwrites are acceptable.
- `--no-commit-log` to skip the per-commit log hook (installed by default in git repos).

Then explain what was created and how the user should replace placeholder feature entries.

### Per-commit log (branch history)

Every commit writes a new JSON file named `<branch>_<dd_mm_yyyy>_<HH-MM-SS>.json` (e.g.
`main_07_10_2026_14-30-05.json`; `/` in branch names becomes `-`) to `.harness/commits/`, with: branch,
hash, author, dates, message, every changed file (status, rename source, added/removed lines) and totals.
On a branch other than the base (`init.defaultBranch`, `main`, `master`, `develop`) it also lists every
commit the branch has since it diverged (`branch_history`). Use these files to answer "what was done on
this branch" instead of guessing from chat history.

```bash
node skills/harness-creator/scripts/commit-log.mjs --install --target /path/to/project   # once per repo
git config core.hooksPath .githooks                                                        # once per new clone
```

The installer copies the script into the repo (`.githooks/commit-log.mjs`), so the hook doesn't depend on
the skill being installed, and sets `core.hooksPath`. It never overwrites a foreign hook or hooks path
without `--force`. A post-commit hook can't amend its own commit, so each JSON is committed together with
the next commit; logs are excluded from their own file lists. The hook never blocks or fails a commit.
Requires a git repository: if the target has none, say so and offer `git init`.

### Audit an existing harness

Run:

```bash
node skills/harness-creator/scripts/validate-harness.mjs --target /path/to/project
```

Report the five subsystem scores, the lowest-scoring area, and the first 2-3 changes that would improve reliability. Treat the lowest score as a candidate bottleneck; confirm with failures, logs, or task outcomes before claiming causality.

### Produce a report

Use when the user wants a shareable assessment:

```bash
node skills/harness-creator/scripts/render-assessment-html.mjs --target /path/to/project
node skills/harness-creator/scripts/run-benchmark.mjs --target /path/to/project --html /path/to/report.html
```

Be clear that this is a structural benchmark. The benchmark first runs a self-check — it scaffolds a throwaway harness and validates it, proving the bundled scripts work end-to-end — then scores the target and eval coverage. Real effectiveness still needs before/after agent sessions on representative tasks.

## When to Read References

Load only the reference needed for the user's problem:

- Memory across sessions: [Memory Persistence](references/memory-persistence-pattern.md)
- Reusable workflows as skills: [Skill Runtime](references/skill-runtime-pattern.md)
- Permissions, tools, concurrency: [Tool Registry & Safety](references/tool-registry-pattern.md)
- Context budget and progressive disclosure: [Context Engineering](references/context-engineering-pattern.md)
- Delegation and parallel agents: [Multi-Agent Coordination](references/multi-agent-pattern.md)
- Hooks, startup, long-running work: [Lifecycle & Bootstrap](references/lifecycle-bootstrap-pattern.md)
- Non-obvious failure modes: [Gotchas](references/gotchas.md)

## Design Rules

- Keep the root instruction file short: routing and invariants, not a full manual.
- Put project facts in project docs, not in the skill.
- Make verification commands explicit and runnable.
- Require evidence before marking a feature done.
- Use one active feature unless the harness has explicit multi-agent ownership boundaries.
- Prefer append/update state files over relying on chat history.
- Never hide destructive behavior in scripts; overwrites require explicit user approval.

## Deliverable Checklist

For a usable minimal harness, leave the target project with:

- [ ] `AGENTS.md` or `CLAUDE.md`
- [ ] `feature_list.json`
- [ ] `progress.md`
- [ ] `init.sh`
- [ ] Optional `session-handoff.md` for multi-session work
- [ ] `.githooks/post-commit` with `core.hooksPath=.githooks` (git repos), writing `.harness/commits/*.json`
- [ ] Documented verification evidence or next action

If you cannot create files, provide exact file contents and commands instead.
