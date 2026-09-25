<?php
// Minimal HTTP endpoint for the Slack sink test, served by PHP's built-in server.
file_put_contents((string) getenv('SLACK_RECEIVER_OUT'), (string) file_get_contents('php://input'));
http_response_code(200);
echo 'ok';
