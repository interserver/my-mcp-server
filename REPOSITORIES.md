# Where `interserver/mcp-openapi-core` comes from

`composer.json` lists two repositories for it, in priority order:

1. **a `path` repository at `../mcp-openapi-core`** — used while the three repos
   move together on one box. Composer symlinks it, so an edit in core is visible
   here immediately.
2. **a `vcs` repository** at `git@github.com:interserver/mcp-openapi-core.git` —
   used everywhere the checkout is not present, which is every deploy target.

The path entry wins locally and is simply skipped when the directory does not
exist, so no per-environment configuration is needed.

**Once core is tagged and published, delete the `path` entry from this file's
`repositories` block.** A symlinked dependency means CI is not testing the version
the deploy will install, and the version drift that produces is precisely what the
shared package exists to prevent.

`composer.lock` is committed. Both prior repos gitignored theirs while depending
on `dev-main`, and that is the direct reason nobody could say which protocol
version was being served.
