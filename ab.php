<?php

ob_start(); # http://stackoverflow.com/questions/9707693/warning-cannot-modify-header-information-headers-already-sent-by-error
if (!isset($_SESSION)) session_start();

class ABExperiment {

	const TAG = 'ABTEST';
	const VALID_CHARACTERS = '/[^a-z0-9 _]*/';
	const TEST_PERCENT_DEFAULT = 50;

	private $_config;
	private $_experiment_name;
	private $_experiment_percent;
	private $_variations;
	private $_variations_percents;
	private $_current_variation;
	private $_identified_visitor;

	/**
	 *	Supply experiment and variation names.
	 *	Optionally, you can also specify the percentage of visitors to include.
	 *	@param string|array $experiment 'Chat test' | array('Chat test' => 50)
	 *	@param array $variations array('Show chat', 'Hide chat') | array('Show chat' => 25, 'Hide chat' => 75)
	 *	@param string|integer|null $user Supply a user id, username or other unique identifier
	 */
	public function __construct($experiment, $variations, $user=null) {
			$this->_config = parse_ini_file(__DIR__ . "/config.ini");
			print_r($this->_config);
			$this->_identified_visitor = $user;
			$this->setExperimentParams($experiment);
			$this->setVariationParams($variations);
			$this->_current_variation = $this->getVariation();	
	}

	/**
	 *	Check if the current visitor has been processed so we don't evaluate 
	 *	whether they should be in the test more than one time per session.
	 *	@return boolean
	 */
	public static function isVisitorProcessed() {
		return isset($_SESSION[_visitor_processed]);
	}

	/**
	 *	Mark this visitor as processed so we don't evaluate whether they
	 *	should be in the test more than one time per session.
	 */
	public static function setVisitorProcessed() {
		$_SESSION[_visitor_processed] = true;
	}

	/**
	 *	Parse the experiment for name and optional percentage of users included.
	 *	@param string|array
	 *	@return array
	 */
	public function setExperimentParams($experiment) {
		if (is_array($experiment) && count($experiment) == 1) {
			foreach($experiment as $name => $percent) {
				if (is_string($name) && is_numeric($percent)) {
					$this->_experiment_name = $this->slugify($name);
					$this->_experiment_percent = $percent;
				}
			}
		} else if (is_string($experiment)) {
			$this->_experiment_name = $this->slugify($experiment);
			$this->_experiment_percent = self::TEST_PERCENT_DEFAULT;
		} else {
			throw new Exception('Invalid experiment name.');
		}
	}

	/**
	 *	Get the experiment name.
	 *	@return string
	 */
	public function getExperimentName() {
		return $this->_experiment_name;
	}

	/**
	 *	Get the percent of visitors that should be included in the experiment.
	 *	@return integer
	 */
	public function getExperimentPercent() {
		return $this->_experiment_percent;
	}

	/**
	 *	Get group variations. If supplied, use those. Otherwise generate
	 *	even distributions.
	 *	@param array $variations
	 */
	public function setVariationParams($variations) {
		$count = count($variations);
		foreach($variations as $key => $value) {
			if (is_string($key) && is_numeric($value)) {
				$this->_variations[] = $key;
				$this->_variations_percents[] = $value;
			} else if (is_string($value)) {
				$this->_variations[] = $value;
				$this->_variations_percents[] = 1/$count;
			}
		} 
		if (count($this->_variations) != count($this->_variations_percents)) throw new Exception('Invalid variations.');
		$this->_variations = $this->slugify($this->_variations);
		$this->_variations_percents = $this->buildCumulativePercentiles($this->_variations_percents);
	}

	/**
	 *	Accepts an array of percentiles representing proportions of groups in a test populations
	 *	Returns a normalized to 1.0 array of cumulative percentiles
	 *	e.g., an array of equal distributions [1,1] or [0.5,0.5] returns [0.5,1.0]
	 *	e.g., an array of unequal distributions [1,2,1] or [0.25,0.5,0.25] returns [0.25,0.75,1.0]
	 *	@param array $dist
	 *	@return array
	 */
	public static function buildCumulativePercentiles($dist) {

		// make sure there's at least one value in the distribution
		if (count($dist) < 1) throw new Exception('Distribution array must have at least one element.');

		// get the sum of the array of values
		$sum = 0.0;
		foreach($dist as $d) {
			if ($d <= 0.0) throw new Exception('Distribution values must be greater than 0');
			$sum += $d;
		}

		$max_index = count($dist)-1;

		// normalize values to 0.0 to 1.0
		foreach (range(0,$max_index) as $i) {
			$dist[$i] = $dist[$i] / $sum;
		}

		// convert to cumulative percentiles
		foreach (range(0,$max_index) as $i) {
			$dist[$i] = $dist[$i] + $dist[$i-1];
		}

		return $dist;
	}

	/**
	 *	Get the experiment tag name.
	 *	@return string
	 */
	public static function getTag() {
		return self::TAG;
	}

	/**
	 *	Check if experiment is enabled.
	 *	@return boolean
	 */
	public function isEnabled() {
		return $this->_config['enabled'];
	}

	/**
	 *	Which variation of the experiment should be displayed?
	 *	@return string|null
	 */
	public function variation() {
		return ($this->isEnabled() ? $this->_current_variation : null);
	}

	/**
	 *	Get the current visitor's experiment variation.
	 *	If one has not already been assigned, choose one. 
	 *	@return string
	 */
	public function getVariation() {
		$cookie = $_COOKIE[$this->getTag()][$this->getExperimentName()];
		return (is_null($cookie) ? $this->chooseVariation() : $cookie);
	}

	/**
	 * 	Assign a variation to the current visitor.
	 *	@return string
	 */
	public function chooseVariation() {
		if ($this->isVisitorProcessed()) return 'default';
		if (!$this->isVisitorIncluded()) return 'default';

		$rand = $this->randomPercentileAsDecimal();
		$i=0;
		foreach($this->_variations as $v) {
			if ($rand <= $this->_variations_percents[$i]) {
				$this->recordVariation($v);
				return $v;
			}
			$i++;
		}
	}

	public function isVisitorIncluded() {
		$this->setVisitorProcessed();

		$rand = $this->randomPercentile();
		return ($rand <= $this->_experiment_percent ? true : false);
	}

	/**
	 *	Set a cookie to record the current visitor's variation
	 *	@param string $variation
	 */
	public function recordVariation($variation) {
		$cookie_name = $this->getTag();
		if ($this->_identified_visitor) $cookie_name .= "[" . $this->_identified_visitor . "]";
		$cookie_name .= "[" . $this->getExperimentName() . "]";
		setcookie($cookie_name, $variation, time()+3600*24*90, "/");
	}

	/**
	 *	Set a test for a specific user as complete.
	 */
	public function complete() {
		if (!is_null($this->variation())) {
			# TODO: Send results somewhere
		}
	}

	/**
	 *	Set a session cookie for the current visitor so they see the same
	 *	variation of this experiment for the duration of the session.
	 */
	public function tagSession() {} #TODO

	/**
	 *	Set a persistent cookie for the current visitor so they see the same
	 *	variation of this experimnt for the duration of the experiment.
	 */
	public function tagVisitor() {} #TODO

	/**
	 *	Try to identify the current visitor from session and/or persistent cookies.
	 *	@return string|null if user can be identified, return unique identifier
	 */
	public function identifyVisitor() {} #TODO

	/**
	 *	Turn a string into a slug 
	 *  e.g., "Hello World" becomes "hello_world"
	 *	@param string $s
	 *	@return string
	 */
	public static function slugifyString($s) {
		$s = trim(strtolower($s));
		$s = preg_replace(self::VALID_CHARACTERS, '', $s);
		$s = str_replace(' ', '_', $s);
		return $s;
	}

	/**
	 *	Turn a string or array values into a slug 
	 *	e.g., "Hello World" becomes "hello_world"
	 *	e.g., array("Hi Mom" => "Hi Dad") becomes array("Hi Mom" => "hi_dad")
	 *	@param string|array $s
	 *	@return string|array
	 */
	public static function slugify($s) {
		try {
			if (is_string($s)) {
				return self::slugifyString($s);
			} elseif (is_array($s)) {
				$s_tmp = array();
				foreach($s as $k=>$v) $s_tmp[$k] = self::slugify($v);
				return $s_tmp;
			} else {
				return $s;
			}
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	/**
	 *	Generate a random number between 0 and 100 inclusive. 
	 *	@return integer
	 */
	public function randomPercentile() {
		return 100 * $this->randomPercentileAsDecimal();
	}

	/**
	 *	Generate a random number between 0.0 and 1.0 inclusive. 
	 *	@return float
	 */
	public static function randomPercentileAsDecimal() {
		return round(mt_rand() / mt_getrandmax(), 2);
	}

	public function getVariationNames() {
		return $this->_variations;
	}

	public function getVariationPercents() {
		return $this->_variations_percents;
	}

}

?>
