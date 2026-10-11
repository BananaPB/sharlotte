---
name: tdd
description: Test-driven development with a red → green loop at the seams agreed in the feature brief. Use when building a feature or fixing a bug, before writing the production code it needs.
---

<!--
Adapted from mattpocock/skills, skills/engineering/tdd (MIT License, Copyright (c) 2026 Matt Pocock),
upstream commit 49dd158. Project changes: seams come from the brief's "Seams" section instead of an
interactive confirmation (so the loop also works unattended), domain vocabulary comes from
docs/domain-model.md, refactoring is handed to the review stage of this repo's pipeline, and the
examples in tests.md / mocking.md gained Pest/Postgres notes. Keep these changes when pulling upstream updates.
-->

# Test-Driven Development

TDD is the red → green loop. This skill is the reference that makes that loop produce tests worth keeping: what a good test is, where tests go, the anti-patterns, and the rules of the loop. Every section applies on every cycle: consult them before and during the loop, not after.

When exploring the codebase, read `docs/domain-model.md` (if the work touches the domain) so test names and interface vocabulary match the project's domain language, and respect the entries in `docs/decisions.md` for the area you're touching.

## What a good test is

Tests verify behavior through public interfaces, not implementation details. Code can change entirely; tests shouldn't. A good test reads like a specification: "it refuses to add a recipe line that would create a cycle" tells you exactly what capability exists, and it survives refactors because it doesn't care about internal structure.

See [tests.md](tests.md) for examples and [mocking.md](mocking.md) for mocking guidelines.

## Seams: where tests go

A **seam** is the public boundary you test at: the interface where you observe behavior without reaching inside. Tests live at seams, never against internals. In this repo the usual seams are an HTTP route (Pest feature test), a model or domain service's public method (Pest unit test), and a React component's rendered output (Vitest).

**Test only at pre-agreed seams.** The seams are agreed while the feature is planned (the `/cto` grilling before a `/feature` or a nightshift run) and written in the brief's **Seams** section, each with a one-line note on what it catches and what it misses.

- The brief lists seams: use exactly those. If one turns out to be impossible to test at (the interface doesn't exist the way the brief assumed), that's a design question, not a testing detail: stop and report it.
- The brief has no Seams section:
    - interactive session: propose the seams (one line each: what it catches, what it misses) and confirm them with the user before writing any test;
    - unattended run (`NIGHTSHIFT=1` is set): don't invent them. Stop and report `escalated` with reason "brief has no Seams section".

You can't test everything, so agreeing the seams up front is how testing effort lands on the critical paths and complex logic instead of every edge case. Edge-case hunting beyond the seams is the `qa` agent's job, after you.

## Anti-patterns

- **Implementation-coupled**: mocks internal collaborators, tests private methods, or verifies through a side channel (querying the database instead of using the interface). The tell: the test breaks when you refactor but behavior hasn't changed.
- **Tautological**: the assertion recomputes the expected value the way the code does (`expect(add(a, b)).toBe(a + b)`, a snapshot derived by hand the same way, a constant asserted equal to itself), so it passes by construction and can never disagree with the code. Expected values must come from an independent source of truth: a known-good literal, a worked example, the brief. For nutrition calculations, that means a hand-computed literal with the arithmetic shown in a comment.
- **Horizontal slicing**: writing all tests first, then all implementation. Bulk tests verify _imagined_ behavior: you test the _shape_ of things rather than user-facing behavior, the tests go insensitive to real changes, and you commit to test structure before understanding the implementation. Work in **vertical slices** instead: one test → one implementation → repeat, each test a **tracer bullet** that responds to what the last cycle taught you.

## Rules of the loop

- **Red before green.** Write the failing test first, run it, and see it fail for the right reason (not a typo or a missing import). Then write only enough code to pass it. Don't anticipate future tests or add speculative features.
- **One slice at a time.** One seam, one test, one minimal implementation per cycle. Run the single test file during the loop (`./vendor/bin/pest tests/Feature/...`), not the whole suite.
- **Refactoring is not part of the loop.** It belongs to the review stage (`/audit`), not the red → green implementation cycle.
