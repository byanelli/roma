<?php

declare(strict_types=1);

/**
 * Renders a self-contained coverage badge SVG from a Clover coverage report.
 *
 * No third-party service is involved: the generated SVG is committed to the
 * repository's `badges` branch by the Coverage workflow and referenced from the
 * README. Coverage is reported as statement (line) coverage.
 *
 * Usage: php coverage-badge.php <clover.xml> <out.svg>
 */
$input = $argv[1] ?? 'coverage.xml';
$output = $argv[2] ?? 'coverage.svg';

$xml = @simplexml_load_file($input);

if ($xml === false) {
    fwrite(STDERR, "coverage-badge: cannot read clover file: {$input}\n");
    exit(1);
}

$metrics = $xml->xpath('/coverage/project/metrics');

if (! $metrics) {
    fwrite(STDERR, "coverage-badge: no <project><metrics> found in {$input}\n");
    exit(1);
}

// The last direct <project><metrics> is the aggregate for the whole run.
$aggregate = end($metrics);
$statements = (int) $aggregate['statements'];
$covered = (int) $aggregate['coveredstatements'];
$percent = $statements > 0 ? (int) floor($covered / $statements * 100) : 0;

// Shields-style coverage thresholds.
$color = match (true) {
    $percent >= 95 => '#4c1',    // brightgreen
    $percent >= 90 => '#97ca00', // green
    $percent >= 75 => '#a4a61d', // yellowgreen
    $percent >= 60 => '#dfb317', // yellow
    $percent >= 40 => '#fe7d37', // orange
    default => '#e05d44',        // red
};

$label = 'coverage';
$value = $percent.'%';

// Geometry. textLength forces the text to fit its slot regardless of the
// renderer's font metrics, so approximate per-character widths are fine.
$labelWidth = 62;
$valueWidth = strlen($value) * 7 + 10;
$totalWidth = $labelWidth + $valueWidth;

$labelX = (int) ($labelWidth * 10 / 2);
$labelTextLength = ($labelWidth - 10) * 10;
$valueX = (int) (($labelWidth + $valueWidth / 2) * 10);
$valueTextLength = ($valueWidth - 12) * 10;

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="{$totalWidth}" height="20" role="img" aria-label="{$label}: {$value}">
  <title>{$label}: {$value}</title>
  <linearGradient id="s" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <clipPath id="r"><rect width="{$totalWidth}" height="20" rx="3" fill="#fff"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$labelWidth}" height="20" fill="#555"/>
    <rect x="{$labelWidth}" width="{$valueWidth}" height="20" fill="{$color}"/>
    <rect width="{$totalWidth}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="110">
    <text x="{$labelX}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$labelTextLength}">{$label}</text>
    <text x="{$labelX}" y="140" transform="scale(.1)" textLength="{$labelTextLength}">{$label}</text>
    <text x="{$valueX}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$valueTextLength}">{$value}</text>
    <text x="{$valueX}" y="140" transform="scale(.1)" textLength="{$valueTextLength}">{$value}</text>
  </g>
</svg>
SVG;

file_put_contents($output, $svg);

echo "coverage-badge: {$percent}% ({$covered}/{$statements} statements) -> {$output}\n";
