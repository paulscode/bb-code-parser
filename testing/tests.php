<!DOCTYPE html>
<html>
	<head>
		<title>jasmine / bb-code-parser tester</title>

		<link rel="shortcut icon" type="image/png" href="jasmine/jasmine_favicon.png">
		<link rel="stylesheet" type="text/css" href="jasmine/jasmine.css">

		<script src="jasmine/jasmine.js"></script>
		<script src="jasmine/jasmine-html.js"></script>

		<script type="text/javascript">
			(function() {
<?php
			include('../bb-code-parser.php');

			function allowed($test, $configuration) {

				if (array_key_exists('run', $test)) {
					return in_array($configuration['key'], $test['run']);

				} else if (array_key_exists('stop', $test)) {
					return !in_array($configuration['key'], $test['stop']);
				}
				return true;
			}

			function describe($name, $configuration, $unitTests) {
?>
				describe('<?php echo $name ?>', function() {
<?php
				foreach($unitTests as $unitTest) {

					if (allowed($unitTest, $configuration)) {
						if (array_key_exists('unitTests', $unitTest)) {
							describe($unitTest['name'], $configuration, $unitTest['unitTests']);
						} else {
							$parser = new BBCodeParser(array_key_exists('constructorArgument', $configuration)? $configuration['constructorArgument'] : null);
?>
					it('<?php echo $unitTest['name'] ?>', function () {
						expect('<?php echo addslashes($parser->format($unitTest['input'], array_key_exists('formatArgument', $configuration)? $configuration['formatArgument'] : null)) ?>').toEqual('<?php echo addslashes($unitTest['result']) ?>');
					});
<?php						}
					}
				}
?>
				});
<?php			}

			$tests = json_decode(file_get_contents("tests.json"), true);

			foreach ($tests['unitConfigurations'] as $configuration) {
				describe($configuration['name'], $configuration, $tests['unitTests']);
			}
?>
				var env = jasmine.getEnv();
				// Default is something like 250 ... I assume this causes it to jive with the interval used when
				// a tab is not active in Firefox and the like. If it takes a long time to update, the browser
				// might generate a dialog which is bad for automated testing.
				env.updateInterval = 1000;

				var reporter = new jasmine.HtmlReporter();
				env.addReporter(reporter);

				// Because it's not documented anywhere else (Google, Jasmine documentation, Jasmine source ...)
				// this causes the output to show only a given set of tests or single test when the appropriate
				// link is clicked on. That wasn't so hard to write, was it? Why can't they do that?
				env.specFilter = function(spec) {
					return reporter.specFilter(spec);
				};

				window.onload = function() {
					env.execute();
				};
			})();
		</script>
	</head>
	<body></body>
</html>
