#!/usr/bin/env php
<?php
// Stands in for the external drush binary in the package's own tests: a real
// process that records what the Drupal sink sends (arguments and stdin), since
// no Drupal site exists here. Never used outside tests.
$out = getenv('FAKE_DRUSH_OUT');
if ($out === false) {
    fwrite(STDERR, "FAKE_DRUSH_OUT not set\n");
    exit(2);
}
if (getenv('FAKE_DRUSH_FAIL') !== false) {
    fwrite(STDERR, "simulated drush failure\n");
    exit(1);
}
$record = ['argv' => array_slice($argv, 1), 'stdin' => json_decode((string) stream_get_contents(STDIN), true)];
file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
