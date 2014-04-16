<?php 

ob_start(); # http://stackoverflow.com/questions/9707693/warning-cannot-modify-header-information-headers-already-sent-by-error
if (!isset($_SESSION)) session_start();

include_once '../ab.php'; 

// $experiment = new ABExperiment('Chat test', array('Show chat', 'Hide chat'));
// $experiment = new ABExperiment(array('Chat test' => 50), array('Show chat', 'Hide chat'));
$experiment = new ABExperiment(array('Chat test' => 50), array('Show chat' => 25, 'Hide chat' => 75), 'allibot');

echo "<pre>";
echo "Test enabled: " . $experiment->isEnabled() . "<br/>";
echo "Test name: " . $experiment->getExperimentName()  . "<br/>";
echo "Test percent: " . $experiment->getExperimentPercent()  . "<br/>";
echo "Test variation names: <br/>";
print_r($experiment->getVariationNames());
echo "Test variation percents: <br/>";
print_r($experiment->getVariationPercents());
echo "Visitor processed? " . json_encode(isset($_SESSION[_visitor_processed])); 
echo "</pre>";

session_destroy();

?>

<html>
	<?php if ($experiment->variation() === 'hide_chat') : ?>
		<p>Hiding chat!</p>
	<?php elseif ($experiment->variation() === 'show_chat') : ?>
		<p>Showing chat!</p>
	<?php elseif ($experiment->variation() === 'default') : ?>
		<p>Default</p>
	<?php endif; ?>
</html>

