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

// Approximate rendered text width (px) at the 11px badge font. The exact figure
// doesn't need to be perfect: the text is drawn with lengthAdjust
// "spacingAndGlyphs", so it scales uniformly to fill textLength — it never
// overlaps the way a too-small "spacing"-only textLength would.
$measure = static function (string $text): int {
    $width = 0;

    foreach (str_split($text) as $char) {
        $width += match (true) {
            $char === '%' => 10,
            ctype_digit($char) => 7,
            ctype_upper($char) => 8,
            $char === 'i' || $char === 'l' || $char === 'j' || $char === 't' => 4,
            default => 7,
        };
    }

    return $width;
};

$labelTextWidth = $measure($label);
$valueTextWidth = $measure($value);

$padding = 6; // per side
$labelWidth = $labelTextWidth + 2 * $padding;
$valueWidth = $valueTextWidth + 2 * $padding;
$totalWidth = $labelWidth + $valueWidth;

// The text sits in a coordinate system scaled by 0.1 (font-size 110 -> 11px),
// so positions and lengths are ten times their pixel values.
$labelCenter = (int) round($labelWidth / 2 * 10);
$valueCenter = (int) round(($labelWidth + $valueWidth / 2) * 10);
$labelTextLength = $labelTextWidth * 10;
$valueTextLength = $valueTextWidth * 10;

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
    <text x="{$labelCenter}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$labelTextLength}" lengthAdjust="spacingAndGlyphs">{$label}</text>
    <text x="{$labelCenter}" y="140" transform="scale(.1)" textLength="{$labelTextLength}" lengthAdjust="spacingAndGlyphs">{$label}</text>
    <text x="{$valueCenter}" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$valueTextLength}" lengthAdjust="spacingAndGlyphs">{$value}</text>
    <text x="{$valueCenter}" y="140" transform="scale(.1)" textLength="{$valueTextLength}" lengthAdjust="spacingAndGlyphs">{$value}</text>
  </g>
</svg>
SVG;

file_put_contents($output, $svg);

echo "coverage-badge: {$percent}% ({$covered}/{$statements} statements) -> {$output}\n";
