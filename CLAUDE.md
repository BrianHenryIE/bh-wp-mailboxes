* Prefer Mockery in unit tests.
* Always run `phpcbf` + `phpcs` on new and edited code
* Always run `phpstan` at max level on new and edited code
* UI changes should have Playwright tests
* Do not auto-commit unless explicitly requested by the user
* Run `composer dump-autoload` after creating new classes or changing namespaces
* Use `declare(strict_types=1);` in all PHP files
* API methods should return simple objects and not arrays unless the array is a simple list of items
* When a bug is discovered outside the scope of a plan, open a GitHub issue for it if it is not a blocker, fix it if necessary
* Sign GitHub comments as `🤖 Generated with Claude Code`
* Do not add property types in PhpDoc when they are clear from the PHP code itself.
* 