<?php
	
ini_set("memory_limit", "256M");
error_reporting(E_ALL ^ E_NOTICE);

require_once 'core/CanvasParser.php';
require_once 'core/OutputCubiio2.php';
require_once 'core/OutputSvg.php';

$outputFormat = isset($argv[1]) ? $argv[1] : null;
$inputFile    = isset($argv[2]) ? $argv[2] : null;

if (!in_array($outputFormat, array('out-cubiio2', 'out-svg')))
{
    echo "ERROR: require define output format one of: { out-cubiio2 | out-svg }"."\n";
    die;
}

if (empty($inputFile))
{
	echo "Require call as:"."\n";
	echo "./parse out-FORMAT input-test.png"."\n";
	echo ""."\n";
	exit();
}

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

$basename = $inputFile;
$basename = str_replace('.png', '', $basename);

if(!file_exists($basename.'.png'))
{
	echo "Error: file not found"."\n";
	echo ""."\n";
	die;
}

// Parse
$canvas = new CanvasParser();
$canvas->mirror_by_x = strpos($basename, 'mir') !== false;
$canvas->rotate90deg = strpos($basename, 'r90') !== false;
$canvas->debugImages = false;
$canvas->debugCoords = false;
$canvas->load($basename);

$shapesFold    = $canvas->buildShapesAsShapes(CanvasParser::MODE_FOLD,    'red',   CanvasParser::ANALYZE_BY_PIXEL);
$shapesEngrave = $canvas->buildShapesAsBitmap(CanvasParser::MODE_ENGRAVE, 'green', CanvasParser::ANALYZE_BY_PIXEL, CanvasParser::ENGRAVE_BY_NOISE);
$shapesCut     = $canvas->buildShapesAsShapes(CanvasParser::MODE_CUT,     'blue',  CanvasParser::ANALYZE_BY_DIFFS, array(
    'skipShapesWithLessPointsCount' => 2,
));

$canvas->close();

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

if ($outputFormat == 'out-cubiio2')
{
    $scale = 10 / 100; # 10mm / 100px = 0.1. So 100 px for 10mm (1cm)

    # Important have txt extension!

    $laser = new OutputCubiio2($basename.".txt", $canvas->width * $scale, $canvas->height * $scale);
    $laser->padding(0, 0);

    # This is preset for "blue paper"
    $laser->build('Fold',    0.25,  900 * 1.0, $shapesFold);
    $laser->build('Engrave', 0.20,  600 * 1.0, $shapesEngrave);
    $laser->build('Cut',     1.00,  550 * 0.8, $shapesCut);
}
else if($outputFormat == 'out-svg')
{
    $scale = 1 / 10 * 2.834643883688892; # 100px = 10mm

    $saver = new OutputSvg($basename."-fold.svg", $canvas->width * $scale, $canvas->height * $scale);
    $saver->build('Fold', $shapesFold, 0);

    $saver = new OutputSvg($basename."-cut.svg",  $canvas->width * $scale, $canvas->height * $scale);
    $saver->build('Cut',  $shapesCut, 2);

    #new OutputSvg($basename."-fold.svg", $canvas->width * $scale, $canvas->height * $scale))->build('Engrave', $shapesEngrave);
}
