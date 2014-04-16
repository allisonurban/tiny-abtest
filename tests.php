<?php

include_once 'ab.php';

class UnitTest {

	function report($actual, $expected) {
		echo "<pre>";
		if ($actual === $expected) {
			echo "OK";
		} else {
			echo "FAIL\t" . json_encode($actual);
		}
		echo "</pre>";
	}

	function test_slugify() {

		$test_vals = array(
			"Hello World", 
			array("Hello World 2"),
			array(2 => "Hello World 3"),
			array("Hello World 4" => 2),
			76,
			0.53,
			array(array(1), 2, "Chicken nU^ggets")
		);

		$expected_vals = array(
			"hello_world",
			array("hello_world_2"),
			array(2 => "hello_world_3"),
			array("Hello World 4" => 2),
			76,
			0.53,
			array(array(1), 2, "chicken_nuggets")
		);

		for($i=0; $i<count($test_vals); $i++) {
			$actual = ABExperiment::slugify($test_vals[$i]);
			$expected = $expected_vals[$i];
			self::report($actual,$expected);
		}

		$array_in = array("Yo mama");
		$array_in_copy = array("Yo mama");
		$array_out = ABExperiment::slugify($array_in_copy);
		self::report($array_in_copy,$array_in);
	}
}

// TESTS
UnitTest::test_slugify();

?>
