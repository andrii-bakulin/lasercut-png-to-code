<?php
	
ini_set("memory_limit", "256M");

require_once 'core/CanvasParser.php';
require_once 'core/OutputCubiio2.php';
require_once 'core/OutputSvg.php';

$outputFormat = $argv[1];
$inputFile    = $argv[2];

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
$canvas->debugImages = true;
$canvas->debugCoords = false;
$canvas->load($basename);

$shapesFold    = $canvas->buildShapesAsShapes(CanvasParser::MODE_FOLD,    'red',   CanvasParser::ANALYZE_BY_PIXEL);
$shapesEngrave = $canvas->buildShapesAsBitmap(CanvasParser::MODE_ENGRAVE, 'green', CanvasParser::ANALYZE_BY_PIXEL, CanvasParser::ENGRAVE_BY_NOISE);
$shapesCut     = $canvas->buildShapesAsShapes(CanvasParser::MODE_CUT,     'blue',  CanvasParser::ANALYZE_BY_DIFFS, array(
    'skipShapesWithLessPointsCount'     => 2,
    'startPointMode'                    => strpos($basename, 'startPointLeftRight') ? CanvasParser::START_POINT_MODE_LeftRight : CanvasParser::START_POINT_MODE_TopBottom,
));

$canvas->close();

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

if ($outputFormat == 'out-cubiio2')
{
    $scale = 10 / 100; # 10mm / 100px = 0.1. So 100 px for 10mm (1cm)

    # Important have txt extension!

    $laser = new Laser($basename.".txt", $canvas->width * $scale, $canvas->height * $scale);
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
    $saver->build('Fold', $shapesFold);

    $saver = new OutputSvg($basename."-cut.svg",  $canvas->width * $scale, $canvas->height * $scale);
    $saver->build('Cut',  $shapesCut);

    #new OutputSvg($basename."-fold.svg", $canvas->width * $scale, $canvas->height * $scale))->build('Engrave', $shapesEngrave);
}
