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

## Phase 0 — Baseline: make everything green

Goal: trustworthy local signal. No new features.

- [ ] 0.1 Run `composer install`, then `vendor/bin/codecept run unit` and `vendor/bin/codecept run wpunit`. Fix failures. Commit `8a71f30` ("Comment out erroring code") left dead/commented code — find it (`git show 8a71f30`), then fix or delete it.
- [ ] 0.2 Run `composer lint` repo-wide on `includes/` and `development-plugin/`. Fix all phpcs and phpstan errors. ⚠️ If phpstan reveals genuine bugs (not just annotations), list them for the user before fixing.
- [ ] 0.3 The `integration` suite references the removed `Ddeboer_Imap` provider (`tests/integration/Providers/Ddeboer_Imap/class-mark-email-read-Test.php`). Port the test intent (marking an email read on the server) to `ImapEngine_Imap_Email_Fetcher` as a contract test, and delete the dead file.
- [ ] 0.4 **Privacy:** `tests/_data/temp/` contains ~35 real-world `.eml` files with real addresses (Outlook, TikTok Shop, etc.). Delete the directory, add it to `.gitignore`. ⚠️ Ask the user whether any should be sanitized (headers/bodies anonymized) and kept as fixtures in `tests/_data/wpunit/` first. Note: files committed to git history remain in history — flag to the user that a history rewrite (`git filter-repo`) may be warranted.
- [ ] 0.5 Verify `npm install && npm run wp-env:start` boots, development-plugin activates (`.wp-env.json` `afterStart`), and `npm run test:e2e` passes the two existing specs (`plugin-status.spec.ts`, `single-email-view.spec.ts`).

**Done when:** unit, wpunit, and e2e suites pass locally; `composer lint` is clean; no real emails in the working tree.

## Phase 1 — CI/CD modernization

Goal: every push runs lint + unit + wpunit + e2e on PHP 8.4. Current workflows are unusable: PHP 7.4 matrix vs `composer.json` `"php": ">=8.4"`, `actions/checkout@v2`, `setup-php@2.11.0`.

- [ ] 1.1 New `.github/workflows/lint.yml`: PHP 8.4, `actions/checkout@v4`, `shivammathur/setup-php@v2`, composer cache; runs `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --memory-limit 1G`. On push + pull_request. Delete `phpcbf.yml` (auto-committing phpcbf fixes is replaced by failing the lint job).
- [ ] 1.2 New `.github/workflows/tests.yml`: PHP 8.4, mysql:8.0 service; runs `codecept run unit` and `codecept run wpunit`. Reuse the env-file pattern from the old workflows (`.env.testing`, `.env.github`).
- [ ] 1.3 Rewrite `codecoverage.yml`: same as 1.2 plus xdebug, `composer test-coverage` (minus the `open` step), publish HTML to gh-pages, regenerate `.github/coverage.svg` badge. ⚠️ Confirm the `gh-pages` branch exists; if not, ask the user to create it.
- [ ] 1.4 New `.github/workflows/e2e.yml`: Node LTS, `npm ci`, `npx playwright install --with-deps chromium`, `npm run wp-env:start`, `npm run test:e2e`; upload `playwright-report/` as artifact on failure.
- [ ] 1.5 Delete `acceptance.yml`, `deploy.yml`, `integration.yml` (the library has no acceptance suite or deploy target; integration is superseded by 1.2 — fold the integration suite into `tests.yml` if it survives Phase 0). Reduce `release.yml` to: on tag, verify CHANGELOG.md contains the tag version. Packagist updates from tags automatically. ⚠️ Confirm the package is registered on Packagist.
- [ ] 1.6 Add `.github/dependabot.yml` for composer, npm, and github-actions ecosystems (monthly).

**Done when:** all four workflows pass on a test branch push.

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

- [ ] 2.1 `API::check_email()` branch coverage: failed-login backoff skip; unknown-credentials-type warning; `bh_wp_mailboxes_fetcher_for_credentials` filter returning a stub; first-run one-week lookback; `bh_wp_mailboxes_fetch_emails_saved_{$plugin_slug}` and `bh_wp_mailboxes_fetch_emails_complete` firing. Then fix the TODO: clear the failed-login option when settings are saved.
- [ ] 2.2 `Email_WP_Post_Repository`: dedup behavior (same guid fetched twice → one post); `is_read_remote` determination TODO; attachment saving via `bh-wp-private-uploads` (`// TODO: save attachments` at line ~200) — ⚠️ attachments are a feature decision; confirm scope with the user before implementing, otherwise test current behavior and leave the TODO.
- [ ] 2.3 `Status_View` (`includes/admin/class-status-view.php` is a TODO stub): implement minimally — per account: last fetched time, last failure time, email count — with wpunit tests and a Playwright spec (rule 3).
- [ ] 2.4 Fetcher mapping tests using the four sanitized fixtures in `tests/_data/wpunit/` (`html-and-plaintext.eml`, `html-no-plain-text.eml`, `non-multipart.eml`, `test_save_new.eml`).
- [ ] 2.5 Add coverage thresholds: fail `codecoverage.yml` below 70% lines initially; record the actual number in PLAN progress notes and raise over time.

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
- [ ] 5.2 Generate the hooks reference with `pronamic/wp-documentor` into the README's existing `<!-- filters -->` markers; add a CI step in `lint.yml` that fails if the committed output is stale.
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
