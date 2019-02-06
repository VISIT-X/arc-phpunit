Arcanist `phpunit` [v7+]
===============================

## Installation

```bash
composer require visit-x/arc-phpunit --dev
```

## Configuration

1. Load the library into phabricator:
<<<<<<< HEAD

```
// .arcconfig
"load": [
	...
	"./vendor/visit-x/arc-phpunit/.arc/phpunit-seven"
]
...
```

2. Add config options for `phpunit` binary and `phpunit.xml`:

```
// .arcconfig
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
