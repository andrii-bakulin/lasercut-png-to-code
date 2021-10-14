<?php

# https://en.wikipedia.org/wiki/G-code
#
#   G0 - TRAVEL XY
#   G1 - LINEAR XY
#   G2 - CW_ARC XYIJ
#   G3 - CCW_ARC XYIJ
#   G20 - UNIT_INCH
#   G21 - UNIT_MM (default)
#   G90 - ABSOLUTE (default)
#   G91 - INCREMENTAL
#   M03 - LASER ON
#   M05 - LASER OFF
#   F - SPEED 0-600 (mm/min)
#   S - POWER 0-255
#

class OutputCubiio2
{
    protected $hf;
    protected $width;
    protected $height;

    protected $x;
    protected $y;

    public function __construct($filename, $width=1.0, $height=1.0)
    {
        $this->hf = fopen($filename, 'w');

        $this->width  = (float) $width;
        $this->height = (float) $height;

        $this->paddingX = 0;
        $this->paddingY = 0;

        $this->x = 0;
        $this->y = 0;

        $this->header();
    }

    public function __destruct()
    {
        fclose($this->hf);
        $this->hf = null;
    }

    public function header()
    {
        $this->write("M05 S0  ; Init laser OFF");
        $this->write("G21     ; Programming in millimeters (mm)");
        $this->write("G90     ; Absolute programming");
        $this->write("");
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    const MAX_SPEED = 1000;

    public function movingStart($speed)
    {
        // Notice: I will use 1000 as a maximum value!
        if ($speed > self::MAX_SPEED)
        {
            echo "Warning: Speed is {$speed} but max is ".self::MAX_SPEED."."."\n";
            $speed = self::MAX_SPEED;
        }

        $this->write("G1 F{$speed}");
    }

    public function movingStop()
    {
        $this->write("G4 P0");
        $this->write("");
    }

    public function moveBy($dx, $dy)
    {
        $this->x += $dx;
        $this->y += $dy;

        $this->write("G1 X{$this->x} Y{$this->y}");
    }

    public function moveTo($x, $y)
    {
        $x += $this->paddingX;
        $y += $this->paddingY;

        $this->x = $x;
        $this->y = $y;

        $this->write("G1 X{$this->x} Y{$this->y}");
    }

    public function moveTo01($x, $y)
    {
        $x *= $this->width;
        $y *= $this->height;

        $this->moveTo($x, $y);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function laserOn($power=1.0)
    {
        $realPower = (int)(255*$power);
        $this->write("M03 S{$realPower} ; laser ON");
        $this->write("G4 P0");
        $this->write("");
    }

    public function laserOff()
    {
        $this->write("M05 S0           ; laser OFF");
        $this->write("G4 P0");
        $this->write("");
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function write($line)
    {
        fwrite($this->hf, $line."\n");
    }

    public function padding($x, $y)
    {
        $this->paddingX = $x;
        $this->paddingY = $y;
    }

    public function build($title, $laserPower, $speed, $shapes)
    {
        $index = 0;
        foreach ($shapes as $k => $shape)
        {
            $this->write("");
            $this->write(";;; SHAPE $title #$index");
            $index++;

            if (count($shape) <= 2)
            {
                $this->write(";;; has ".count($shape)." points. Skipped!");
                continue;
            }

            list($x,$y) = $shape[0];
            $y = 1 - $y;

            $this->movingStart(self::MAX_SPEED);
            $this->moveTo01($x, $y);
            $this->movingStop();

            $this->laserOn($laserPower);
            $this->movingStart($speed);
            foreach ($shape as $coords)
            {
                list($x,$y) = $coords;
                $y = 1 - $y;

                $this->moveTo01($x,$y);
            }
            $this->movingStop();
            $this->laserOff();
        }
    }
}
