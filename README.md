# react-saas

A multi-organization SaaS starter with a Laravel API and a React app, being rebuilt from [saas-starter](../saas-starter) (Laravel + Nuxt) one feature at a time, as a way to learn React. Tasks take the place of Projects.

## Stack
- **API (`api/`):** Laravel, PostgreSQL, Sanctum token auth, Pest
- **Web (`web/`):** Vite, React 19, TypeScript, React Router, TanStack Query, Zustand, shadcn/ui + Tailwind, react-hook-form + zod, Vitest

## Status
Only the tooling exists so far. Setup and run steps arrive with plan 02 (API) and plan 03 (web). The roadmap is in [docs/plans](docs/plans/README.md).

## Working with Claude Code
The repo ships its Claude Code setup in `.claude/` and `.mcp.json`: skills for planning, verifying, e2e checks and shipping, reviewer subagents, and hooks that block destructive database commands. [CLAUDE.md](CLAUDE.md) has the conventions.
