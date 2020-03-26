<?php
/**
 * @author Igor Timoshenkov [it@campoint.net]
 * @started: 26.06.2018 15:56
 */

/**
 * PHPUnit wrapper.
 */
class PHPUnit7Engine extends ArcanistUnitTestEngine {

	private $configFile;
	private $phpunitBinary = __DIR__ . '/../../../phpunit.phar';
	private $affectedTests;
	private $projectRoot;

	public function run() {
		$this->projectRoot   = $this->getWorkingCopy()->getProjectRoot();
		$this->affectedTests = [];

		foreach ($this->getPaths() as $path) {
			$path = Filesystem::resolvePath($path, $this->projectRoot);

			// TODO: add support for directories
			// Users can call phpunit on the directory themselves
			if (is_dir($path)) {
				continue;
			}

			// Not sure if it would make sense to go further if
			// it is not a .php file
			if (substr($path, -4) != '.php') {
				continue;
			}

			if ($test = $this->findTestFile($path)) {
				if (!Filesystem::pathExists($test)) {
					continue;
				}

				$this->affectedTests[$path] = basename($test, '.php');
			}

			if (substr($path, -8) == 'Test.php') {
				// Looks like a valid test file name.
				$this->affectedTests[$path] = basename($path, '.php');
				continue;
			}
		}

		if (empty($this->affectedTests)) {
			throw new ArcanistNoEffectException(pht('No tests to run.'));
		}

		$this->prepareConfigFile();

		$jsonTmp   = new TempFile();
		$cloverTmp = null;
		$clover    = null;

		if ($this->getEnableCoverage() !== false) {
			$cloverTmp = new TempFile();
			$clover    = csprintf('--coverage-clover %s --whitelist %s', $cloverTmp, $this->projectRoot . '/src');
		}

		$config    = $this->configFile ? csprintf('-c %s', $this->configFile) : null;
		$stderr    = '-d display_errors=stderr';
		$testsPath = implode('|', array_unique($this->affectedTests));
		$cmd       = '%C %C %C %s %C --filter %s';

		$future   = new ExecFuture($cmd, $this->phpunitBinary, $config, $stderr, $jsonTmp, $clover, $testsPath);
		$tmpfiles = ['json' => $jsonTmp, 'clover' => $cloverTmp];

		list($err, $stdout, $stderr) = $future->resolve();

		return $this->parseTestResults($testsPath, $tmpfiles['json'], $tmpfiles['clover'], $stderr);
	}

	/**
	 * Parse test results from phpunit json report.
	 *
	 * @param string $path       Path to test
	 * @param string $json_tmp   Path to phpunit json report
	 * @param string $clover_tmp Path to phpunit clover report
	 * @param string $stderr     Data written to stderr
	 *
	 * @return array
	 */
	private function parseTestResults($path, $json_tmp, $clover_tmp, $stderr) {
		$test_results = Filesystem::readFile($json_tmp);
		return id(new PHPUnit7Parser())
			->setEnableCoverage($this->getEnableCoverage())
			->setProjectRoot($this->projectRoot)
			->setCoverageFile($clover_tmp)
			->setAffectedTests($this->affectedTests)
			->setStderr($stderr)
			->parseTestResults($path, $test_results);
	}

	/**
	 * Search for test cases for a given file in a large number of "reasonable"
	 * locations. See @{method:getSearchLocationsForTests} for specifics.
	 *
	 * TODO: Add support for finding tests in testsuite folders from
	 * phpunit.xml configuration.
	 *
	 * @param   string      PHP file to locate test cases for.
	 *
	 * @return  string|null Path to test cases, or null.
	 */
	private function findTestFile($path) {
		$root = $this->projectRoot;
		$path = Filesystem::resolvePath($path, $root);

		$file           = basename($path);
		$possible_files = [
			$file,
			substr($file, 0, -4) . 'Test.php',
		];

		$search = $this->getSearchLocationsForTests($path);

		foreach ($search as $search_path) {
			foreach ($possible_files as $possible_file) {
				$full_path = $search_path . $possible_file;
				if (!Filesystem::pathExists($full_path)) {
					// If the file doesn't exist, it's clearly a miss.
					continue;
				}
				if (!Filesystem::isDescendant($full_path, $root)) {
					// Don't look above the project root.
					continue;
				}
				if (0 == strcasecmp(Filesystem::resolvePath($full_path), $path)) {
					// Don't return the original file.
					continue;
				}
				return $full_path;
			}
		}

		return null;
	}

	/**
	 * Get places to look for PHP Unit tests that cover a given file. For some
	 * file "/a/b/c/X.php", we look in the same directory:
	 *
	 *  /a/b/c/
	 *
	 * We then look in all parent directories for a directory named "tests/"
	 * (or "Tests/"):
	 *
	 *  /a/b/c/tests/
	 *  /a/b/tests/
	 *  /a/tests/
	 *  /tests/
	 *
	 * We also try to replace each directory component with "tests/":
	 *
	 *  /a/b/tests/
	 *  /a/tests/c/
	 *  /tests/b/c/
	 *
	 * We also try to add "tests/" at each directory level:
	 *
	 *  /a/b/c/tests/
	 *  /a/b/tests/c/
	 *  /a/tests/b/c/
	 *  /tests/a/b/c/
	 *
	 * This finds tests with a layout like:
	 *
	 *  docs/
	 *  src/
	 *  tests/
	 *
	 * ...or similar. This list will be further pruned by the caller; it is
	 * intentionally filesystem-agnostic to be unit testable.
	 *
	 * @param   string        PHP file to locate test cases for.
	 *
	 * @return  list<string>  List of directories to search for tests in.
	 */
	public function getSearchLocationsForTests($path) {
		$file = basename($path);
		$dir  = dirname($path);

		$test_dir_names = $this->getConfigurationManager()->getConfigFromAnySource(
			'unit.phpunit.test-dirs'
		);

		if (empty($test_dir_names)) {
			$test_dir_names = ['tests', 'Tests'];
		}

		$try_directories = [];

		// Try in the current directory.
		$try_directories[] = [$dir];

		// Try in a tests/ directory anywhere in the ancestry.
		foreach (Filesystem::walkToRoot($dir) as $parent_dir) {
			if ($parent_dir == '/') {
				// We'll restore this later.
				$parent_dir = '';
			}
			foreach ($test_dir_names as $test_dir_name) {
				$try_directories[] = [$parent_dir, $test_dir_name];
			}
		}

		// Try replacing each directory component with 'tests/'.
		$parts = trim($dir, DIRECTORY_SEPARATOR);
		$parts = explode(DIRECTORY_SEPARATOR, $parts);
		foreach (array_reverse(array_keys($parts)) as $key) {
			foreach ($test_dir_names as $test_dir_name) {
				$try       = $parts;
				$try[$key] = $test_dir_name;
				array_unshift($try, '');
				$try_directories[] = $try;
			}
		}

		// Try adding 'tests/' at each level.
		foreach (array_reverse(array_keys($parts)) as $key) {
			foreach ($test_dir_names as $test_dir_name) {
				$try       = $parts;
				$try[$key] = $test_dir_name . DIRECTORY_SEPARATOR . $try[$key];
				array_unshift($try, '');
				$try_directories[] = $try;
			}
		}

		$results = [];
		foreach ($try_directories as $parts) {
			$results[implode(DIRECTORY_SEPARATOR, $parts) . DIRECTORY_SEPARATOR] = true;
		}

		return array_keys($results);
	}

	/**
	 * Tries to find and update phpunit configuration file based on
	 * `phpunit_config` option in `.arcconfig`.
	 */
	private function prepareConfigFile() {
		$project_root = $this->projectRoot . DIRECTORY_SEPARATOR;
		$config       = $this->getConfigurationManager()->getConfigFromAnySource('unit.phpunit.config');

		if ($config) {
			if (Filesystem::pathExists($project_root . $config)) {
				$this->configFile = $project_root . $config;
			} else {
				throw new Exception(pht('PHPUnit configuration file was not found in %s', $project_root . $config));
			}
		}
		$bin = $this->getConfigurationManager()->getConfigFromAnySource('unit.phpunit.binary');

		if ($bin) {
			if (Filesystem::binaryExists($bin)) {
				$this->phpunitBinary = $bin;
			} else {
				$this->phpunitBinary = Filesystem::resolvePath($bin, $project_root);
			}
		}
	}

}