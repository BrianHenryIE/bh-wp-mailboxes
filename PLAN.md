# bh-wp-mailboxes — Execution Plan

A phased plan for an AI coding agent (Claude Sonnet 4.6) to bring this library to a releasable state: green test suites, demonstration via the development-plugin, working CI/CD, and accurate README documentation.

## Context (read before starting)

This is a **Composer library** (`brianhenryie/bh-wp-mailboxes`), not a standalone plugin. It downloads emails into WordPress via IMAP (`Providers/Imap/class-imapengine-imap-email-fetcher.php`, using `directorytree/imapengine`) or the Gmail API (`Providers/Gmail_API/class-gmail-email-fetcher.php`), saves them to a custom post type via `includes/api/repositories/class-email-wp-post-repository.php`, and fires `bh_wp_mailboxes_fetch_emails_saved_{$plugin_slug}` for the parent plugin to act on. Entry point: `API::check_email()` in `includes/api/class-api.php`, scheduled by `includes/WP_Includes/class-cron.php`.

Read these before any work: `README.md`, `ARCHITECTURE.md`, `CLAUDE.md`, `includes/api/class-api.php`, `development-plugin/development-plugin.php`.

## Ground rules (apply to every task)

1. **Lint everything you touch:** `vendor/bin/phpcbf <files>`, then `vendor/bin/phpcs <files>`, then `vendor/bin/phpstan analyse --memory-limit 1G`. All must pass before a task is "done". (`composer lint` runs all three.)
2. **Mockery in unit tests** (not raw PHPUnit mocks). Unit suite uses WP_Mock + Patchwork; see `tests/unit/class-unit-testcase.php`.
3. **Playwright tests for any UI change**, in `tests/e2e-pw/specs/`. Run with `npm run wp-env:start` then `npm run test:e2e`.
4. **Test naming convention:** mirror the source path, e.g. `includes/api/class-api.php` → `tests/unit/api/class-api-unit-Test.php`. One test class per source class. Use full, unambiguous names in test method names, e.g. `test_check_email_skips_account_after_recent_failed_login()`.
5. **Suites:** `vendor/bin/codecept run unit` (no WordPress), `run wpunit` (WPLoader/database), `run integration` (full WordPress), `run contract` (live mailbox credentials — never run in CI; skip when `test-credentials/` env vars are absent).
6. **Do not invent features.** The README's CLI section and credentials-storage musings are open design questions — move them to `docs/open-questions.md`, do not implement them.
7. **Never commit credentials** or anything from `test-credentials/`. Never weaken `development-plugin/class-authentication.php` guards (it must only load inside wp-env).
8. **One phase per branch/PR.** Within a phase, commit per task with descriptive messages.
9. **Stop and ask the user** at any checkpoint marked ⚠️.

---

## Phase 0 — Baseline: make everything green ✅ (PR #1, merged)

Goal: trustworthy local signal. No new features.

- [x] 0.1 Run `composer install`, then `vendor/bin/codecept run unit` and `vendor/bin/codecept run wpunit`. Fix failures. Commit `8a71f30` ("Comment out erroring code") left dead/commented code — find it (`git show 8a71f30`), then fix or delete it.
- [x] 0.2 Run `composer lint` repo-wide on `includes/` and `development-plugin/`. Fix all phpcs and phpstan errors.
- [x] 0.3 Ported to `tests/contract/Providers/Imap/class-imapengine-email-fetcher-contract-Test.php`; dead `Ddeboer_Imap` file deleted. The `integration` suite survives (hooks + API tests) and has its own workflow.
- [x] 0.4 `tests/_data/temp/` is gitignored and untracked. ⚠️ Still open: the ~35 real `.eml` files remain in git history — a `git filter-repo` rewrite is warranted; flag before any history-sensitive work.
- [x] 0.5 wp-env boots, development-plugin activates, both e2e specs pass.

**Phase 0 also delivered most of the originally planned Phase 1:** the old PHP 7.4-era workflows were replaced rather than rewritten per the original task list. Current workflows (all PHP 8.4): `phpcbf.yml` (phpcbf auto-commit on master + phpcs annotations via cs2pr; on PRs, fails only on errors in changed lines via `kamazee/pr-filter`), `phpstan.yml` (annotations repo-wide; fails on `includes/` on master, on changed files in PRs), `unit-coverage.yml` (unit + wpunit with mysql:8.0, coverage merged and published to gh-pages, `.github/coverage.svg` badge, PR coverage comment), `integration.yml` (codecept integration suite), `e2e.yml` (Playwright against wp-env, report artifacts). `acceptance.yml`, `deploy.yml`, and `release.yml` are deleted; `dependabot.yml` (daily, composer + npm + github-actions) is in place; the `gh-pages` branch exists.

## Phase 1 — CI/CD hardening

Goal: close the gaps the Phase 0 workflow rewrite left open. Do **not** restructure the existing five workflows — the auto-commit phpcbf approach and the PR diff-filtering are deliberate; keep them.

- [x] 1.1 Aligned action versions across all five workflows: `actions/checkout@v6`, `actions/upload-artifact@v7`, `shivammathur/setup-php@2.37.2`, `stefanzweifel/git-auto-commit-action@v7.1.0`. Also added artifact upload to `phpcbf.yml`.
- [x] 1.2 Fixed `e2e.yml` package-manager mix-up: `yarn playwright install` → `npx playwright install --with-deps chromium`.
- [x] 1.3 Coverage gate: set `percentage: 33`, `exit: true`, `metric: statements` (33.3% measured; `metric: elements` was 31.9% — pinned to `statements` for consistency with local measurement).
- [x] 1.4 New `release.yml`: on `v*.*.*` tag, verifies `CHANGELOG.md` contains the tag version. ⚠️ Confirm `brianhenryie/bh-wp-mailboxes` is registered on Packagist before tagging.
- [x] 1.5 Throwaway PR #8 validated diff-filtering: phpcs and phpstan failed on touched-file errors; untouched-file errors were annotations-only (phpstan skips non-`includes/` paths; phpcs uses `kamazee/pr-filter`). Discovery: coverage action defaults to `metric: elements` — fixed in 1.3 update.
- [ ] 1.6 ⚠️ Branch protection on `master`: ask the user to require the five workflow checks before merge (or set via `gh api` if permitted) — without it, the PR-only failure logic in `phpcbf.yml`/`phpstan.yml` is advisory.

**Done when:** a test PR shows annotations + coverage comment and all five workflows are required and green; the coverage gate demonstrably fails below threshold; a test tag passes `release.yml`.

## Phase 2 — Unit test coverage of the core pipeline

Goal: every class in `includes/` has a test file. Current coverage gaps — these classes have **zero tests**:

| Class | Suite | Focus |
|---|---|---|
| `ImapEngine_Imap_Email_Fetcher` | unit | message→`BH_Email` mapping with Mockery-mocked ImapEngine client; date-range filtering |
| `Gmail_Email_Fetcher` | unit | same, mocking `Google\Service\Gmail`; the existing contract test needs live credentials |
| `Email_Account_WP_Post_Repository` | wpunit | save/retrieve account; address immutability rule from README |
| `BH_Email_Account` (model) | unit | construction, getters |
| `BH_Email_Factory` | unit | building `BH_Email` from parsed `IMessage` (use `tests/_data/wpunit/*.eml` fixtures) |
| `BH_Email_Query` + `WP_Post_Query_Abstract` + `WP_Posts_Query_Order` | wpunit | meta-query building; the `is_read_remote` null-handling TODO |
| `Admin_Notices` | unit | notice added on auth failure, cleared on success |
| `Status_View` | unit | currently a TODO stub — see 2.3 |
| `BH_WP_Mailboxes_Hooks` | unit | every `add_action`/`add_filter` registered (integration test exists, no unit test) |
| `BH_Email_Account_CPT` | wpunit | CPT registration args |
| settings traits (`trait-bh-wp-mailboxes-settings-defaults.php`, `trait-email-account-settings-defaults.php`) | unit | default values |

And these are **thin** (≤1 test method): `tests/wpunit/API/class-api-wpunit-Test.php`, `tests/wpunit/repository/class-email-wp-post-repository-WPUnit-Test.php`, `tests/wpunit/Model/class-bh-email-wpunit-Test.php`, `tests/wpunit/WP_Includes/class-cron-wpunit-Test.php`.

Tasks (each = tests first, then resolve the embedded TODO the tests pin down):

- [x] 2.1 `API::check_email()` branch coverage in `tests/unit/api/class-api-unit-Test.php` (moved from `tests/unit/Providers/` per naming convention): failed-login backoff skip, unknown-credentials-type warning, stub fetcher via filter, first-run one-week lookback + stored-time lookback, dedup reject before `save_all()`, both actions firing with the saved emails, `check_email_for_account()` (explicit since + default lookback). TODO resolved as explicit `Email_Account_WP_Post_Repository::clear_last_failed_login_time()` — **the parent plugin must call it from its settings-save handler**. Also: fetch exceptions now record `last_failed_login_time` (previously nothing set it, so the 4-hour backoff was dead code — note: *any* fetch exception triggers backoff, not just auth failures); fixed latent bug where `BH_Email_Account_Query`/`BH_Email_Query` dropped `post_id`, so both repositories' `update()` called `wp_update_post()` without `ID` (regression-tested in `tests/unit/api/repositories/queries/`). Remaining gaps: `update()` round-trips still need the planned wpunit tests (table row above); `clear_last_failed_login_time()` has unit coverage only; wpunit suite not runnable in the sandbox (no MySQL) — verify in CI.
- [x] 2.2.1 `Email_WP_Post_Repository`: dedup behavior (same guid fetched twice → one post);
- [x] 2.2.2 `is_read_remote` determination: captured at fetch time (IMAP `\Seen` / Gmail `UNREAD` label) and stored; `get_is_marked_read()` re-checks via a stored remote id (IMAP UID + UIDVALIDITY + folder, Gmail message id) with a Message-ID search fallback. See `Remote_Email_Coordinates` / `Fetched_Email`.
- [x] 2.2.3 attachment saving via `bh-wp-private-uploads`: whWe nen the private-uploads `API_Interface` is supplied (threaded from `API` through `save_all`/`save_new`), each attachment is saved via `move_file_to_private_uploads_and_create_post()` and the post ids stored on the `BH_Email` (`attachment_ids`). Factory now distinguishes disabled (`null`) from none (`[]`). Caveat: `move_file_to_private_uploads()` checks `current_user_can('upload_files')`, which fails in pure cron — per-attachment failures are logged and the email still saves.
- [x] 2.3 `Status_View` (`includes/admin/class-status-view.php` is a TODO stub): implement minimally — per account: last fetched time, last failure time, email count — with wpunit tests and a Playwright spec (rule 3).
- [x] 2.4 Fetcher mapping tests using the sanitized fixtures: `tests/unit/Providers/Imap/class-imapengine-imap-email-fetcher-unit-Test.php` (Mailbox mocked via reflection; real `MessageCollection` of mocked `MessageInterface`s; asserts UID/folder/UIDVALIDITY/Message-ID/`\Seen` mapping + the since-time date filter) and `tests/unit/Providers/Gmail_API/class-gmail-email-fetcher-unit-Test.php` (extracted a `Gmail_Email_Fetcher::get_gmail_service()` seam to inject a mock service; asserts Gmail-id→remote_uid mapping + `UNREAD`-label read state). `BH_Email_Factory::from_wp_post` mapping was already covered by the factory wpunit test.
- [x] 2.5 Raised the `unit-coverage.yml` ratchet 33% → **45%** (`metric: statements`). Measured statement coverage of `includes/` after this phase is **48.0%** (1551 statements, 744 covered; unit + wpunit merged via `phpcov`). The gate is set just under the measured figure as a regression floor; pushing toward 70% needs the still-untested admin view classes (`Single_Email_View`, `Emails_List_Page`, etc.) and provider edge paths covered.

**Done when:** every class in `includes/` has a corresponding test file and the table above is empty.

## Phase 3 — development-plugin as the living example

Goal: a developer (or an e2e test) can exercise the full pipeline with zero real mailboxes.

- [ ] 3.1 `development-plugin/providers/class-stub-email-fetcher.php`: implements `Email_Fetcher_Interface`, returns `BH_Email`s parsed from bundled `.eml` fixtures (reuse `development-plugin/mailboxes/class-fixtures.php` if it already does this — read it first). Register via the `bh_wp_mailboxes_fetcher_for_credentials` filter for a fake "stub@example.org" account.
- [ ] 3.2 Make `development-plugin/admin/class-settings-page.php` the canonical reference implementation of `BH_WP_Mailboxes_Settings_Interface` + `Email_Account_Settings_Interface`, with PhpDoc explaining each choice (CPT name, cron schedule, credentials supply). This file gets linked from the README.
- [ ] 3.3 Extend `development-plugin/rest/class-mailboxes.php` (namespace `bh-wp-mailboxes-dev/v1`, existing routes `/status` and `/emails`): add `POST /fetch` (runs `API::check_email()` with the stub fetcher) and `DELETE /emails` (reset state). These exist for Playwright arrange/act steps.
- [ ] 3.4 Demonstrate consuming the `bh_wp_mailboxes_fetch_emails_saved_{$plugin_slug}` action in the development-plugin (e.g. log each new email's subject via `bh-wp-logger`) — this is the example parent-plugin integration.

**Done when:** `wp-env start` → `POST /wp-json/bh-wp-mailboxes-dev/v1/fetch` → emails appear in the WP_List_Table, all without credentials.

## Phase 4 — End-to-end Playwright suite

Existing: `plugin-status.spec.ts`, `single-email-view.spec.ts`. Add (arrange via dev-plugin REST, UI only for the behavior under test):

- [ ] 4.1 `emails-list-table.spec.ts`: list renders fixture emails; search and account-filter work.
- [ ] 4.2 `fetch-pipeline.spec.ts`: `POST /fetch` (stub fetcher) → new email visible in list; second fetch → no duplicate row (dedup).
- [ ] 4.3 `single-email-view.spec.ts` extension: HTML vs plain-text rendering for each of the three fixture body types; assert HTML is sandboxed/escaped (no script execution from a fixture containing a `<script>` tag — add one).
- [ ] 4.4 `status-view.spec.ts`: the Phase 2.3 status page shows per-account last-fetched data after a fetch.
- [ ] 4.5 `auth-failure-notice.spec.ts`: stub fetcher throws auth exception → admin notice appears; per `README.md` Goals, verify no immediate retry.

**Done when:** all specs pass locally and in `e2e.yml`.

## Phase 5 — Documentation

- [ ] 5.1 Rewrite `README.md`: what it does (keep the existing examples) → installation (`composer require brianhenryie/bh-wp-mailboxes`) → 5-minute integration walkthrough referencing the development-plugin files from 3.2/3.4 → actions & filters reference → Gmail API setup (keep existing section) → GDPR section (keep, tighten). Move the CLI sketch, credentials-storage questions, and "Should we keep a historic count" to `docs/open-questions.md`.
- [ ] 5.2 Generate the hooks reference with `pronamic/wp-documentor` into the README's existing `<!-- filters -->` markers; add a CI step in `phpcbf.yml` that fails if the committed output is stale.
- [ ] 5.3 Update `ARCHITECTURE.md` with a diagram: Cron → `API::check_email()` → Fetcher (IMAP / Gmail / filter-supplied) → `Email_WP_Post_Repository` → CPT → action. Document the settings/credentials split (library never stores credentials).
- [ ] 5.4 Populate `CHANGELOG.md` (currently 13 bytes) following keepachangelog.com; document dev setup in `CONTRIBUTING.md` (wp-env, suites, e2e).
- [ ] 5.5 Fix the README badge row: "WordPress tested 7.0" links to a wordpress.org plugin page that does not exist for a library — point badges at the GitHub repo/actions instead.

**Done when:** a developer can integrate the library using only README + development-plugin, and every documented hook exists in code.

---

## Verification checklist (run at the end of every phase)

```bash
composer lint                       # phpcbf + phpcs + phpstan
vendor/bin/codecept run unit
vendor/bin/codecept run wpunit
npm run wp-env:start && npm run test:e2e
```

## Out of scope (do not do without explicit user approval)

WP-CLI commands; credentials persistence; autodelete/retention scheduling changes; Gmail OAuth UI flows; publishing to wordpress.org; git history rewriting (flag it, don't do it).
