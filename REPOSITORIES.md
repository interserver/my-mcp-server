# Where `interserver/mcp-openapi-core` comes from

A **`vcs` repository** at `git@github.com:interserver/mcp-openapi-core.git`,
pinned to `^0.1` with a **committed `composer.lock`**.

That means CI and every deploy install the same artefact — the tagged release, not
a working copy. During the initial build this was a Composer `path` repository
symlinked to `../mcp-openapi-core`, which is convenient and wrong: a symlinked
dependency means CI is not testing the version the deploy will install, and that
kind of drift is exactly what the shared package exists to prevent. It has been
removed.

## Access

The repository is private, so anything that runs `composer install` needs a
credential:

- **CI** — a deploy key in the `MCP_CORE_DEPLOY_KEY` secret, loaded by the
  `webfactory/ssh-agent` step in `.github/workflows/ci.yml`.
- **Deploy hosts** — an SSH key for the deploy user, or a Composer auth token.

## Releasing a change to the core

Three repositories move together:

1. tag core (`v0.1.1`, `v0.2.0`, …) and push the tag;
2. `composer update interserver/mcp-openapi-core` here, run the tests, commit the
   lock;
3. do the same in `my-admin-mcp-server`.

Do not leave one application on an older core across a protocol change. Both prior
repos gitignored their locks while depending on `dev-main`, and that is the direct
reason nobody could say which protocol version was being served.
