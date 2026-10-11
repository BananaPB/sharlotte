---
name: Feature brief
about: A brief precise enough to be built without anyone to ask — by /feature, or by a nightshift run
title: ''
labels: ''
---

<!--
Written by /cto (nightshift planning mode) after a grilling session, or by hand.
Add the `nightshift:ready` label only once every section is filled: an unattended run cannot ask
about anything left blank. The structure follows the to-spec template from mattpocock/skills (MIT),
trimmed to what this repo's agents read.
-->

## Problem

What the user can't do today, from their point of view.

## Solution

What they will be able to do, from their point of view.

## Acceptance criteria

Observable and testable. The `spec` reviewer checks each one; the PR lists the test that proves it.

- [ ] ...
- [ ] ...

## Seams

Where the tests go (the `tdd` skill only tests here). One line each: what it catches, what it misses.

- `POST /...` (Pest feature test) — catches ...; misses ...
- `Model::method()` (Pest unit test) — catches ...; misses ...

## Zones touched

Files, tables, routes this will change. Two briefs in the same night must not overlap here.

## Out of scope

What must NOT be built now, even if it looks related.

## High-risk changes allowed

Leave "none" unless this feature genuinely needs one of the changes listed in `CLAUDE.md` §10 — then name it. Anything listed there and not named here makes the run stop and ask.

none
