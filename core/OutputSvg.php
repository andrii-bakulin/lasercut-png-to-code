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

    public function build($title, $shapes)
    {
        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="no"?>');
        $this->write('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" baseProfile="full" width="100" height="100">');

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
        }

        $this->write('</svg>');
    }

    protected function write($line)
    {
        fwrite($this->hf, $line."\n");
    }
}
