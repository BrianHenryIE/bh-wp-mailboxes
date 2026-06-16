* Prefer Mockery in unit tests.
* Always run `phpcbf` + `phpcs` + `phpstan` on new and edited code
* UI changes should have Playwright tests
* Do not auto-commit unless explicitly requested by the user
* Run `composer dump-autoload` after creating new classes or changing namespaces
* Use `declare(strict_types=1);` in all PHP files
* API methods should return simple objects and not arrays unless the array is a simple list of items
* 