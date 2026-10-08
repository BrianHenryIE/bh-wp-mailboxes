* Prefer Mockery in unit tests.
* Always run `phpcbf` + `phpcs` on new and edited code
* Always run `phpstan` at max level on new and edited code
* UI changes should have Playwright tests
* Do not auto-commit unless explicitly requested by the user
* Run `composer dump-autoload` after creating new classes or changing namespaces
* Use `declare(strict_types=1);` in all PHP files – add it when absent in a file being edited
* API methods should return simple objects and not arrays unless the array is a simple list of items
* When a bug is discovered outside the scope of a plan, open a GitHub issue for it if it is not a blocker, fix it if necessary
* Sign GitHub comments as `🤖 Generated with Claude Code`
* Do not add property types in PhpDoc when they are clear from the PHP code itself.
* Before pushing, test under WordPress Playground (`composer playground-serve`, then exercise the changed behavior at http://127.0.0.1:9400) — the self-contained build nests the library in the plugin's vendor directory, so wp-env's mapped layout masks path/bootstrap bugs.
* Prefer underscores in option names
* When we introduce new properties to typed classes, we generally need a default so old versions don't fail entirely
* Use PSR-3 placeholders in log messages (not string concatenation)
* Use SOLID software design
* Prefer instance methods. Static methods are allowed (but not preferred) when there are no side effects.
* Write unit tests for all bugfixes
* Write unit tests for all new features
* Don't add PhpDoc return type when it is the same as the PHP function signature return type
* Playwright E2E tests should use REST and WP CLI to arrange the test and only use UI for the minimal part being tested. The assertion should preferably be via REST but UI is reasonable if the page loaded shows the result. Custom REST endpoints for arranging tests can be added in development-plugin/rest. Do not use REST endpoints when a setting does not need to be changed during a test, instead use tests/_wp-env/initialize-internal.sh.
* PRs with UI changes should contain screenshots of changes
* methods that are hooked to WordPress actions and filters should have PhpDoc `@hooked` annotation with the name and `@see` annotation linking to the call site, preferably the function or method, otherwise the file and line number. Action names with variables/concatenation should be appended to the `@see` line (name only, no "do_action"/"apply_filters").
* Do not use `add_action()` or `add_filter()` in constructors, add a `register_hooks()` method and call it from outside the class.
* In composer.json do not pin the versions for WordPress plugins because they often do not follow semver, and failing with the latest is more important that compatability with older versions
* `includes/admin` is for `/wp-admin/` user interface display and handling
* `includes/frontend` is for the website frontend UI display and handling – i.e. users see and interact with this
* `templates` is for HTML which can be overridden by other plugins. There should be minimal logic in these files
* I.e. do not add methods unrelated to user interface in `includes/admin`, `includes/frontend`, or `templates` – business logic does not belong there
* DO NOT delete dead code. (I want to understand it first)
* Use verbose variable names (i.e. rarely ever single character or acronyms)
* Never return WP_Error – either handle the problem or wrap it in an Exception
* Write to logs whenever data is changed
* Use PHP constructor property promotion
* Keep development-plugin in sync with changes to the actual plugin
* When adding Playwright tests, delete generated data on complete/teardown
