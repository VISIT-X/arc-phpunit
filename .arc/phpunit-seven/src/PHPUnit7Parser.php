<?php
/**
 * @author Igor Timoshenkov [it@campoint.net]
 * @started: 26.06.2018 15:58
 */

class PHPUnit7Parser extends ArcanistTestResultParser {

	/**
	 * Parse test results from phpunit json report
	 *
	 * @param string $path         Path to test, a list of test paths
	 * @param string $test_results String containing phpunit XML report
	 *
	 * @return array
	 */
	public function parseTestResults($path, $test_results) {
		if (!$test_results) {
			$result = id(new ArcanistUnitTestResult())
				->setName($path)
				->setUserData($this->stderr)
				->setResult(ArcanistUnitTestResult::RESULT_BROKEN);
			return [$result];
		}
		// coverage is for all testcases in the executed $path
		$coverage = [];
		if ($this->enableCoverage !== false) {
			$coverage = $this->readCoverage();
		}
		$last_test_finished = true;
		$results            = $this->getJunitReport($test_results, $coverage);
		return $results;
	}

	/**
	 * Read the coverage from phpunit generated clover report
	 *
	 * @return array
	 */
	private function readCoverage() {
		$test_results = Filesystem::readFile($this->coverageFile);
		if (empty($test_results)) {
			return [];
		}

		$coverage_dom = new DOMDocument();
		$coverage_dom->loadXML($test_results);
		$reports = [];
		$files   = $coverage_dom->getElementsByTagName('file');
		foreach ($files as $file) {
			$class_path = $file->getAttribute('name');
			if (empty($this->affectedTests[$class_path])) {
				continue;
			}
			$test_path = $this->affectedTests[$file->getAttribute('name')];
			// get total line count in file
			$line_count       = count(file($class_path));
			$any_line_covered = false;

			$lines    = $file->getElementsByTagName('line');
			$coverage = str_repeat('N', $line_count);
			foreach ($lines as $line) {
				if ($line->getAttribute('type') != 'stmt') {
					continue;
				}
				if ((int)$line->getAttribute('count') > 0) {
					$is_covered       = 'C';
					$any_line_covered = true;
				} else {
					$is_covered = 'U';
				}
				$line_no                = (int)$line->getAttribute('num');
				$coverage[$line_no - 1] = $is_covered;
			}
			// Sometimes the Clover coverage gives false positives on uncovered lines
			// when the file wasn't actually part of the test. This filters out files
			// with no coverage which helps give more accurate overall results.
			if ($any_line_covered) {
				$len                  = strlen($this->projectRoot . DIRECTORY_SEPARATOR);
				$class_path           = substr($class_path, $len);
				$reports[$class_path] = $coverage;
			}
		}
		return $reports;
	}

	/**
	 * @param string $test_results
	 * @param string $coverage
	 *
	 * @return array
	 * @throws Exception
	 */
	private function getJunitReport($test_results = '', $coverage = null) {
		if ('' === $test_results) {
			throw new Exception(
				pht(
					'%s argument to %s must not be empty',
					'test_results',
					'parseTestResults()'));
		}
		// xunit xsd: https://gist.github.com/959290
		$xunit_dom    = new DOMDocument();
		$load_success = @$xunit_dom->loadXML($test_results);

		if (!$load_success) {
			$input_start = id(new PhutilUTF8StringTruncator())
				->setMaximumGlyphs(150)
				->truncateString($test_results);
			throw new Exception(
				sprintf(
					"%s\n\n%s",
					pht('Failed to load XUnit report; Input starts with:'),
					$input_start));
		}

		$results   = [];
		$testcases = $xunit_dom->getElementsByTagName('testcase');

		foreach ($testcases as $testcase) {
			$classname = $testcase->getAttribute('class');
			$name      = $testcase->getAttribute('name');
			$time      = $testcase->getAttribute('time');
			$status    = ArcanistUnitTestResult::RESULT_PASS;
			$user_data = '';
			// A skipped test is a test which was ignored using framework
			// mechanisms (e.g. @skip decorator)
			$skipped = $testcase->getElementsByTagName('skipped');
			if ($skipped->length > 0) {
				$status   = ArcanistUnitTestResult::RESULT_SKIP;
				$messages = [];
				for ($ii = 0; $ii < $skipped->length; $ii++) {
					$messages[] = trim($skipped->item($ii)->nodeValue, " \n");
				}
				$user_data .= implode("\n", $messages);
			}
			// A warning is a test which was ignored due to any reason
			$warning = $testcase->getElementsByTagName('warning');
			if ($warning->length > 0) {
				$status   = ArcanistUnitTestResult::RESULT_SKIP;
				$messages = [];
				for ($ii = 0; $ii < $warning->length; $ii++) {
					$messages[] = trim($warning->item($ii)->nodeValue, " \n");
				}
				$user_data .= implode("\n", $messages);
			}
			// Failure is a test which the code has explicitly failed by using
			// the mechanisms for that purpose. e.g., via an assertEquals
			$failures = $testcase->getElementsByTagName('failure');
			if ($failures->length > 0) {
				$status   = ArcanistUnitTestResult::RESULT_FAIL;
				$messages = [];
				for ($ii = 0; $ii < $failures->length; $ii++) {
					$messages[] = trim($failures->item($ii)->nodeValue, " \n");
				}
				$user_data .= implode("\n", $messages) . "\n";
			}
			// An errored test is one that had an unanticipated problem. e.g., an
			// unchecked throwable, or a problem with an implementation of the test.
			$errors = $testcase->getElementsByTagName('error');
			if ($errors->length > 0) {
				$status   = ArcanistUnitTestResult::RESULT_BROKEN;
				$messages = [];
				for ($ii = 0; $ii < $errors->length; $ii++) {
					$messages[] = trim($errors->item($ii)->nodeValue, " \n");
				}
				$user_data .= implode("\n", $messages) . "\n";
			}
			$result = new ArcanistUnitTestResult();
			if ($name !== 'Warning') {
				$result->setName($classname . '::' . $name);
			}
			$result->setResult($status);
			$result->setDuration((float)$time);
			$result->setUserData($user_data);
			$result->setCoverage($coverage);
			$results[] = $result;
		}
		return $results;
	}
}
