<?php
// tests/bootstrap.php

// Ensure errors are displayed during tests
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Require the Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// You can add any other global test setup here, e.g.:
// - Loading environment variables for tests
// - Setting up a test database connection or truncating tables before tests
// - Defining global helper functions for tests

// Example: Define a constant for the project root
define('PROJECT_ROOT', dirname(__DIR__));

echo "Test bootstrap loaded.\n";
?>
