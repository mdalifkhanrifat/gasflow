<?php

/**
 * Migration rules that a single CI run on a new database cannot catch.
 *
 * migrate:fresh drops tables (and the views on them) but keeps PostgreSQL
 * functions, so a plain CREATE FUNCTION fails the second time a developer or
 * the test suite rebuilds the database. Functions and views are always
 * created with CREATE OR REPLACE.
 */
it('creates functions and views with CREATE OR REPLACE', function () {
    // Arrange
    $files = array_merge(
        glob(__DIR__.'/../../database/migrations/*.php') ?: [],
        glob(__DIR__.'/../Fixtures/migrations/*.php') ?: [],
    );

    // Act
    $offending = array_values(array_map('basename', array_filter(
        $files,
        fn (string $file) => preg_match('~\bCREATE\s+(FUNCTION|VIEW)\b~i', (string) file_get_contents($file)) === 1,
    )));

    // Assert
    expect($offending)->toBe([]);
});
