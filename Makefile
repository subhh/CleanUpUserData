.PHONY: test
test: test.phpstan test.phpunit test.php-cs-fixer

.PHONY: test.phpstan
test.phpstan:
	phpstan --level=7 analyze src

.PHONY: test.phpunit
test.phpunit:
	phpunit --bootstrap phpunit-bootstrap.php src

.PHONY: test.php-cs-fixer
test.php-cs-fixer:
	php-cs-fixer --verbose check Module.php
	php-cs-fixer --verbose check
