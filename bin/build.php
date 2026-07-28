<?php
/**
 * Build the distributable plugin zip(s) for a GitHub release.
 *
 * Usage:
 *   php bin/build.php          (or: composer build)
 *
 * Output, written to dist/:
 *   listing-moderation-for-hivepress.zip            <- attach THIS as the release asset
 *   listing-moderation-for-hivepress-<version>.zip  <- versioned copy, for your own tracking only
 *
 * Both archives contain an identical top-level folder named exactly
 * "listing-moderation-for-hivepress/", so WordPress installs the plugin into
 * the correct folder with no "destination folder already exists" mismatch.
 *
 * IMPORTANT: the stable-named zip must keep the exact name
 * "listing-moderation-for-hivepress.zip" on every release. Both the in-plugin
 * GitHub updater and the always-latest download link
 *   https://github.com/<owner>/<repo>/releases/latest/download/listing-moderation-for-hivepress.zip
 * resolve that fixed asset name.
 *
 * Only the files a user needs are shipped (allowlist below). Dev tooling
 * (CI config, coding-standards config, Composer files, this script, tests)
 * is deliberately excluded.
 *
 * @package Automated_Listing_Moderation_For_HivePress
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This is a command-line build script. Run: php bin/build.php\n" );
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension (ZipArchive) is required to build the plugin.\n" );
	exit( 1 );
}

$slug = 'listing-moderation-for-hivepress';
$root = dirname( __DIR__ );
$main = $root . '/' . $slug . '.php';

if ( ! is_readable( $main ) ) {
	fwrite( STDERR, "Cannot find the main plugin file: $main\n" );
	exit( 1 );
}

// Read the version from the plugin header so the versioned zip is named to match.
$version = '0.0.0';
if ( preg_match( '/^[ \t\/*]*Version:\s*(.+)$/mi', (string) file_get_contents( $main ), $m ) ) {
	$version = trim( $m[1] );
}

// Allowlist of what ships inside the plugin folder.
$ship_files = array( $slug . '.php', 'uninstall.php', 'readme.txt', 'LICENSE' );
$ship_dirs  = array( 'languages' );

// Resolve the concrete file list (relative to the repo root).
$entries = array();

foreach ( $ship_files as $rel ) {
	if ( is_readable( $root . '/' . $rel ) ) {
		$entries[] = $rel;
	}
}

foreach ( $ship_dirs as $dir ) {
	$base = $root . '/' . $dir;

	if ( ! is_dir( $base ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$entries[] = substr( $file->getPathname(), strlen( $root ) + 1 );
		}
	}
}

sort( $entries );

if ( ! $entries ) {
	fwrite( STDERR, "Nothing to package — no shippable files were found.\n" );
	exit( 1 );
}

// Prepare dist/.
$dist = $root . '/dist';
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0755, true ) && ! is_dir( $dist ) ) {
	fwrite( STDERR, "Cannot create the dist directory.\n" );
	exit( 1 );
}

$targets = array(
	'release'   => $dist . '/' . $slug . '.zip',
	'versioned' => $dist . '/' . $slug . '-' . $version . '.zip',
);

foreach ( $targets as $target ) {
	if ( file_exists( $target ) && ! unlink( $target ) ) {
		fwrite( STDERR, "Cannot overwrite existing file: $target\n" );
		exit( 1 );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
		fwrite( STDERR, "Cannot create archive: $target\n" );
		exit( 1 );
	}

	$zip->addEmptyDir( $slug );

	foreach ( $entries as $rel ) {
		$zip->addFile( $root . '/' . $rel, $slug . '/' . $rel );
	}

	$zip->close();
}

printf( "Built %s version %s (%d files)\n", $slug, $version, count( $entries ) );

foreach ( $targets as $label => $target ) {
	printf( "  %-9s %s  (%d KB)\n", $label, basename( $target ), (int) round( filesize( $target ) / 1024 ) );
}

echo "\nTo publish a release:\n";
echo "  1. Ensure the Version header and readme.txt Stable tag match, and the repo is public.\n";
printf( "  2. Create a GitHub release whose tag matches the version (e.g. %s or v%s).\n", $version, $version );
printf( "  3. Attach dist/%s.zip as a release asset — keep that exact name.\n", $slug );
echo "  4. Publish. Existing installs update automatically; the latest-download link always serves it.\n";
