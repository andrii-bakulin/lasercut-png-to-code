<?php

class OutputSvg
{
    protected $hf;
    protected $width;
    protected $height;

    public function __construct($filename, $width=1.0, $height=1.0)
    {
        $this->hf = fopen($filename, 'w');

        $this->width  = (float) $width;
        $this->height = (float) $height;
    }

    public function __destruct()
    {
        fclose($this->hf);
        $this->hf = null;
    }

    public function build($title, $shapes, $smoothLevel)
    {
        if ($smoothLevel > 0)
        {
            $shapes = $this->smoothShapes($shapes, $smoothLevel);
        }

        $width  = $this->width;
        $height = $this->height;

        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="no"?>');
        $this->write('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" baseProfile="full" width="'.$width.'" height="'.$height.'">');

        $index = 0;
        foreach ($shapes as $k => $shape)
        {
            $this->write('');
            $this->write("<!-- SHAPE $title #$index -->");
            $index++;

            if (count($shape) <= 2)
            {
                $this->write("<!--  has ".count($shape)." points. Skipped! -->");
                continue;
            }

            $endCoords = [];

            foreach ($shape as $coords)
            {
                list($x,$y) = $coords;

                $xx = $x * $this->width;
                $yy = $y * $this->height;

                $endCoords[] = "$xx,$yy";
            }

            $endCoords = join(' ', $endCoords);
            $this->write('<polyline points="'.$endCoords.'" stroke="black" stroke-width="1"/>');
            $this->write('');
        }

        $this->write('</svg>');
    }

    protected function smoothShapes($shapes, $smoothLevel)
    {
        $shapesNew = [];

        foreach ($shapes as $k => $shape)
        {
            $shapeNew = [];
            $count = count($shape);
            for ($i=0; $i<=$count; $i++)
            {
                $xAvg = 0;
                $yAvg = 0;
                $cAvg = 1 + $smoothLevel * 2;

                for ($d=-$smoothLevel; $d<=+$smoothLevel; $d++)
                {
                    $index = $i + $d;
                    if ($index < 0)
                        $index += $count;
                    $index %= $count;

                    list($x,$y) = $shape[$index];

                    $xAvg += $x;
                    $yAvg += $y;
                }

                $xAvg /= $cAvg;
                $yAvg /= $cAvg;

                $shapeNew[] = [$xAvg, $yAvg];
            }

            $shapesNew[$k] = $shapeNew;
        }

        return $shapesNew;
    }

    protected function write($line)
    {
        fwrite($this->hf, $line."\n");
    }
}
