# Parse png file and create code for laser cut

I use this script for two lasers

- Cubiio2
- Flux Beamo Smart Co2 Laser

How to run

```
php parse.php out-cubiio2 samples/sample01.png
[or]
php parse.php out-svg samples/sample01.png
```

## Dimensions

```
10px = 1mm
100px = 1cm
```

## Bonus

Filename will be checked for special keywords:

```
mir - will flip image horizontally
r90 - will rotate image on 90'
```

Examples:

```
testimage-mir.png
testimage-mir-r90.png
```

## Laser Configs

For Flux Beamo I use next params to cut+fold paper:

```
Fold Lines = Power=15, Speed=20
Cut  Lines = Power=60, Speed=20
```
