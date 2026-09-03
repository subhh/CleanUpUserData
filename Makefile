.PHONY: test
test: test.phpstan test.phpunit test.php-cs-fixer

.PHONY: test.phpstan
test.phpstan:
	vendor/bin/phpstan --level=7 analyze src

.PHONY: test.phpunit
test.phpunit:
	vendor/bin/phpunit --bootstrap phpunit-bootstrap.php -c tests/unit-tests/phpunit.xml

.PHONY: test.php-cs-fixer
test.php-cs-fixer:
	vendor/bin/php-cs-fixer --verbose check Module.php
	vendor/bin/php-cs-fixer --verbose check
