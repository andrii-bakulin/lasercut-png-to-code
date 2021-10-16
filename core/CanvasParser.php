<?php

class CanvasParser
{
    const MODE_FOLD                         = 'fold';
    const MODE_ENGRAVE                      = 'engrave';
    const MODE_CUT                          = 'cut';

    const ANALYZE_BY_PIXEL                  = 'analyze-by-pixel';
    const ANALYZE_BY_DIFFS                  = 'analyze-by-diffs';

    const ENGRAVE_BY_LINES                  = 'engrave-by-lines';
    const ENGRAVE_BY_NOISE                  = 'engrave-by-noise';

    const START_POINT_MODE_TopBottom        = 'top-bottom';
    const START_POINT_MODE_LeftRight        = 'left-right';

    private $img;
    private $imgBasename;

    public $width;
    public $height;
    public $mirror_by_x = false;
    public $rotate90deg = false;

    public $debugImages = false;
    public $debugCoords = false;

    private $imgDebug = null;

    private $laser_at_x = 0;
    private $laser_at_y = 0;

    public function __construct()
    {
    }

    public function load($basename)
    {
        $this->imgBasename = $basename;

        $this->img = imagecreatefrompng($basename.'.png');

        if ($this->rotate90deg)
        {
            $this->width  = imagesy($this->img);
            $this->height = imagesx($this->img);
        }
        else
        {
            $this->width  = imagesx($this->img);
            $this->height = imagesy($this->img);
        }

        $this->debugOpen();
    }

    protected function getPixelState($x, $y, $rgbKey)
    {
        if ($this->mirror_by_x)
        {
            $x = ($this->width-1) - $x;
        }

        if ($this->rotate90deg)
        {
            $_x = $x;
            $_y = $y;

            $y = $_x;
            $x = ($this->height-1) - $_y;
        }

        $rgba = imagecolorsforindex($this->img, imagecolorat($this->img, $x, $y));

        $value = $rgba[$rgbKey] / 255;

        return $value > 0.5;
    }

    public function buildShapesAsShapes($mode, $rgbKey, $analyzerType, $options=null)
    {
        if (!is_array($options))
            $options = array();

        if (!isset($options['skipShapesWithLessPointsCount']))
            $options['skipShapesWithLessPointsCount'] = 0;

        if (!isset($options['startPointMode']))
            $options['startPointMode'] = self::START_POINT_MODE_TopBottom;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $delta = 5;

        # Main
        $offsets = [
            [0,0], # will be used as direction from prev.state. To try move in same direction in next calculation
            [-1,0], [+1,0], [0,+1], [0,-1]
        ];

        # Others
        for($y=0; $y<=+$delta; $y++)
        {
            for($x=0; $x<=+$delta; $x++)
            {
                $offsets[] = [ $x, +$y];
                $offsets[] = [-$x, +$y];
                $offsets[] = [+$x, -$y];
                $offsets[] = [-$x, -$y];
            }
        }

        $shapes = array();

        # - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $data = $this->buildPointData($rgbKey, $analyzerType);

        $areas = $this->splitDataToAreas($data);

        while(!empty($areas))
        {
            $area = $this->extractCloserAreaToLaserPosition($areas);

            while(!empty($area))
            {
                $shape = array();

                # I findCloserPointToLaserPosition -> then any shape can start from the middle of line.
                # I think that's not good.
                #
                # list($x0, $y0) = $this->findCloserPointToLaserPosition($area);
                #
                # So, I want to find any "top/bottom" point :) -> choose closer!

                $y_min = min(array_keys($area));
                $x_min = min(array_keys($area[$y_min]));

                $y_max = max(array_keys($area));
                $x_max = min(array_keys($area[$y_max]));

                $d_min = sqrt(($this->laser_at_x-$x_min)**2 + ($this->laser_at_y-$y_min)**2);
                $d_max = sqrt(($this->laser_at_x-$x_max)**2 + ($this->laser_at_y-$y_max)**2);

                if ($d_min <= $d_max)
                    list($x0, $y0) = array($x_min, $y_min);
                else
                    list($x0, $y0) = array($x_max, $y_max);

                $x = $x0;
                $y = $y0;

                while(true)
                {
                    $this->unsetPointInData($area, $x, $y);
                    $shape[] = array($x,$y);

                    $next_x = null;
                    $next_y = null;
                    $next_d = null; # distance

                    foreach($offsets as $offset)
                    {
                        list($dx,$dy) = $offset;

                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if (!isset($area[$ny]) || !isset($area[$ny][$nx]))
                            continue;

                        $d = sqrt($dx**2 + $dy**2);

                        if ($next_d === null || $next_d > $d)
                        {
                            if ($dx > 0)    $offsets[0] = [+1, 0];
                            if ($dx == 0)   $offsets[0] = [ 0, 0];
                            if ($dx < 0)    $offsets[0] = [-1, 0];

                            $next_x = $nx;
                            $next_y = $ny;
                            $next_d = $d;
                        }
                    }

                    if ($next_d === null)
                        break;

                    $x = $next_x;
                    $y = $next_y;

                    $this->laser_at_x = $x;
                    $this->laser_at_y = $y;
                }

                if (count($shape) <= $options['skipShapesWithLessPointsCount'])
                    continue;

                if (sqrt(($x0-$x)**2 + ($y0-$y)**2) < $delta)
                    $shape[] = array($x0,$y0);

                $shapes[] = $shape;
            }
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($mode == self::MODE_CUT)
        {
            // Reorder $shapesCut from "min amount of points" to "max amount of points"
            // Why?
            // Main outline cut shape should have maximum amounts of points -> so cut it as a last shape!

            $shapesNew = array();

            $index = 0;
            foreach ($shapes as $shape)
            {
                if (count($shape) <= 2)
                    continue;

                $key = sprintf("%05d", floor(count($shape))/1000).':'.$index;
                $shapesNew[$key] = $shape;
                $index++;
            }
            $shapes = $shapesNew;
            unset($shapesNew, $shape);

            ksort($shapes);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        foreach ($shapes as $shape)
        {
            $this->debugCoordsList($rgbKey, $shape);
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        return $this->normalizeShapes($shapes);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function buildShapesAsBitmap($mode, $rgbKey, $analyzerType, $engraveType)
    {
        $shapes = array();
        $data = $this->buildPointData($rgbKey, $analyzerType);

        $areas = $this->splitDataToAreas($data);

        while(!empty($areas))
        {
            $area = $this->extractCloserAreaToLaserPosition($areas);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $oneBigShape = array();

            if($engraveType == self::ENGRAVE_BY_LINES)
            {
                $ys = array_keys($area);
                sort($ys);

                foreach($ys as $y)
                {
                    $xs = array_keys($area[$y]);
                    $xMin = min($xs);
                    $xMax = max($xs);

                    $subShapes = array();
                    $subShape = array();

                    for($x=$xMin; $x<=$xMax;$x++)
                    {
                        if ($area[$y][$x])
                        {
                            $subShape[] = [$x, $y];
                            $oneBigShape[] = [$x, $y];
                        }
                        else
                        {
                            if (!empty($subShape))
                            {
                                $subShapes[] = $subShape;
                                $subShape = array();
                            }
                        }
                    }
                    $subShapes[] = $subShape;

                    foreach($subShapes as $subShape)
                    {
                        $shapes[] = $subShape;

                        if ($mode != self::MODE_ENGRAVE)
                            $this->debugCoordsList($rgbKey, $subShape);
                    }
                }
            }
            else if($engraveType == self::ENGRAVE_BY_NOISE)
            {
                srand($this->width + $this->height);

                while(!empty($area))
                {
                    $subShape = array();

                    $y = min(array_keys($area));
                    $x = min(array_keys($area[$y]));

                    while(isset($area[$y][$x]))
                    {
                        $this->unsetPointInData($area, $x, $y);
                        $subShape[] = [$x, $y];
                        $oneBigShape[] = [$x, $y];

                        $moves = array();

                        foreach([[-1,-1],[+1,-1],[-1,+1],[+1,+1]] as $off)
                        {
                            list($dx,$dy) = $off;

                            if(isset($area[$y+$dy][$x+$dx]))
                                $moves[] = array($x+$dx,$y+$dy);
                        }

                        if(empty($moves))
                            break;

                        list($x,$y) = $moves[rand(0, count($moves)-1)];

                        if (count($subShape) == 50)
                            break;
                    }

                    $shapes[] = $subShape;
                    # $this->debugCoordsList($rgbKey, $subShape);
                }
            }
            else
                die('ERROR: Undefined engrave-type');

            $this->debugCoordsList($rgbKey, $oneBigShape);
        }

        return $this->normalizeShapes($shapes);
    }

    protected function splitDataToAreas($data)
    {
        $areas = array();

        while(!empty($data))
        {
            $y = key($data);
            $x = key($data[$y]);

            $area = array();
            $this->splitDataToAreas_parse($data, $x, $y, $area);

            $areas[] = $area;
        }

        return $areas;
    }

    protected function splitDataToAreas_parse(&$data, $x, $y, &$area)
    {
        if(!isset($data[$y]) || !isset($data[$y][$x]))
            return;

        $area[$y][$x] = true;
        $this->unsetPointInData($data, $x, $y);

        $this->splitDataToAreas_parse($data, $x-1, $y, $area);
        $this->splitDataToAreas_parse($data, $x+1, $y, $area);
        $this->splitDataToAreas_parse($data, $x, $y+1, $area);
        $this->splitDataToAreas_parse($data, $x, $y-1, $area);

        $this->splitDataToAreas_parse($data, $x-1, $y-1, $area);
        $this->splitDataToAreas_parse($data, $x+1, $y-1, $area);
        $this->splitDataToAreas_parse($data, $x-1, $y+1, $area);
        $this->splitDataToAreas_parse($data, $x+1, $y+1, $area);
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function normalizeShapes($shapes)
    {
        $normalShapes = array();

        foreach ($shapes as $shape)
        {
            $nShape = array();

            foreach ($shape as $xy)
            {
                $nShape[] = array($xy[0]/$this->width, $xy[1]/$this->height);
            }

            $normalShapes[] = $nShape;
        }

        return $normalShapes;
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function buildPointData($rgbKey, $analyzerType)
    {
        $data = array();

        for ($y = 0; $y < $this->height; $y++)
        {
            for ($x = 0; $x < $this->width; $x++)
            {
                if ($analyzerType == self::ANALYZE_BY_PIXEL)
                {
                    if ($this->getPixelState($x, $y, $rgbKey) == false)
                        continue;
                }
                else if($analyzerType == self::ANALYZE_BY_DIFFS)
                {
                    $px0 = $this->getPixelState($x, $y, $rgbKey);

                    if ($px0 == false)
                        continue;

                    $pxL = $x > 0               ? $this->getPixelState($x-1, $y,   $rgbKey) : true; // true -> as $px0
                    $pxR = $x < $this->width-1  ? $this->getPixelState($x+1, $y,   $rgbKey) : true; // true -> as $px0
                    $pxT = $y > 0               ? $this->getPixelState($x,   $y-1, $rgbKey) : true; // true -> as $px0
                    $pxB = $y < $this->height-1 ? $this->getPixelState($x,   $y+1, $rgbKey) : true; // true -> as $px0

                    if($pxL && $pxR && $pxT && $pxB)
                        continue;
                }
                else
                    continue;

                $data[$y][$x] = true;
            }
        }

        return $data;
    }

    protected function findCloserPointToLaserPosition($data)
    {
        $X = $Y = $D = null;

        foreach($data as $y => $x_list)
        {
            foreach($x_list as $x => $_)
            {
                $d = sqrt(($x-$this->laser_at_x)**2 + ($y-$this->laser_at_y)**2);

                if ($D === null or $D > $d)
                {
                    $X = $x;
                    $Y = $y;
                    $D = $d;
                }
            }
        }

        return array($X, $Y, $D);
    }

    protected function swapAreaXY($area)
    {
        $area_swapped = array();

        foreach ($area as $y => $subarea)
        {
            foreach ($subarea as $x => $v)
            {
                $area_swapped[$x][$y] = $v;
            }
        }

        return $area_swapped;
    }

    protected function extractCloserAreaToLaserPosition(&$areas)
    {
        // Find closes area
        $closes_dst = null;
        $closes_idx = null;

        foreach($areas as $idx => $area)
        {
            list($_,$_,$distance) = $this->findCloserPointToLaserPosition($area);

            if( $closes_dst === null || $closes_dst > $distance)
            {
                $closes_dst = $distance;
                $closes_idx = $idx;
            }
        }

        $area = $areas[$closes_idx];
        unset($areas[$closes_idx]);

        return $area;
    }

    protected function unsetPointInData(&$data, $x, $y)
    {
        unset($data[$y][$x]);

        if (count($data[$y]) == 0)
            unset($data[$y]);
    }

    //------------------------------------------------------------------------------------------------------------------

    private $debugLayerId = 0;

    function debugOpen()
    {
        if (!$this->debugImages && !$this->debugCoords)
            return;

        if (!is_dir($this->imgBasename))
            mkdir($this->imgBasename, 0755);
    }

    function debugCoordsList($rgbKey, $coords)
    {
        $this->debugLayerId++;

        if ($this->debugImages)
        {
            # Write in global layer
            if ($this->imgDebug === null)
            {
                $this->imgDebug = imagecreatetruecolor($this->width, $this->height);
                imagefill($this->imgDebug, 0, 0, imagecolorallocate($this->imgDebug, 0,0,0));
            }
            $this->fillCoordsListInImage($this->imgDebug, $rgbKey, $coords);

            # Write separate layer
            $imgLayer = imagecreatetruecolor($this->width, $this->height);
            imagefill($imgLayer, 0, 0, imagecolorallocate($imgLayer, 0,0,0));
            $this->fillCoordsListInImage($imgLayer, $rgbKey, $coords);
            imagepng($imgLayer, $this->imgBasename."/layer-".sprintf("%02d", $this->debugLayerId)."-{$rgbKey}.png");
            unset($imgLayer);
        }

        if ($this->debugCoords)
        {
            # Write ASCII

            $hf = fopen($this->imgBasename."/datalayer-".sprintf("%02d", $this->debugLayerId)."-{$rgbKey}.txt", 'w');
            foreach ($coords as $xy)
            {
                fwrite($hf, "{$xy[0]} {$xy[1]}"."\n");
            }
            fclose($hf);
        }
    }

    function fillCoordsListInImage($img, $rgbKey, $coords)
    {
        switch($rgbKey)
        {
            case 'red':     $color = imagecolorallocate($img, 255,  0,  0); break;
            case 'green':   $color = imagecolorallocate($img,   0,255,  0); break;
            case 'blue':    $color = imagecolorallocate($img,   0,  0,255); break;
            default:        $color = imagecolorallocate($img, 255,255,255); break;
        }

        foreach ($coords as $xy)
        {
            imagesetpixel($img, $xy[0], $xy[1], $color);
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    public function close()
    {
        if ($this->imgDebug !== null)
            imagepng($this->imgDebug, $this->imgBasename."/layers.png");
    }
}