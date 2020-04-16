Arcanist `phpunit` [v7+]
===============================

## Installation

```bash
composer require visit-x/arc-phpunit
```

## Configuration

1. Load the library into phabricator:

```json
// .arcconfig
"load": [
	...
	"./vendor/visit-x/arc-phpunit/src/phpunit-seven"
]
...
```

2. Add config options for `phpunit` binary and `phpunit.xml`:

```json
// .arcconfig
"unit.engine": "PHPUnit7Engine",
"unit.phpunit.binary": "./vendor/bin/phpunit",
"unit.phpunit.config": "./path/to/phpunit.xml",
"unit.phpunit.test-dirs": [
	"tests/some-folder",
	"tests/another-folder"
]
...
```

## Run the unit test engine

```bash
arc unit
```
