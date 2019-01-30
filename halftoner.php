<?php
$options = getopt('s:p:d:S:l:a:hvjei');
$defaults = array(
    'verbose'=>array('opt'=>'v','val'=>false,'desc'=>'running in verbose mode'),
    'source'=>array('opt'=>'s','val'=>'','desc'=>'Source file: [val]'),
    'dots'=>array('opt'=>'d','val'=>80,'desc'=>'Creating image with [val] dots/lines'),
    'avg_alg'=>array('opt'=>'a','val'=>2,'desc'=>'Using averaging algorithm: [val]','var'=>'return $avg_algs[$val];'),
    'lumin_thresh'=>array('opt'=>'l','val'=>9,'desc'=>'Luminosity threshold of [val]%'),
    'shape'=>array('opt'=>'S','val'=>'c','desc'=>'Dot/output shape [val]','var'=>'return $shapes[$val];'),
    'percent'=>array('opt'=>'p','val'=>90,'desc'=>'Coverage coverage [val]%'),
    'jpg'=>array('opt'=>'j','val'=>false,'desc'=>'writing JPEG preview'),
    'equalize'=>array('opt'=>'e','val'=>false,'desc'=>'equalizing histogram'),
    'invert'=>array('opt'=>'i','val'=>false,'desc'=>'inverting luminosity'),

);
$shapes = array('c'=>'circles','h'=>'hexagons',
    'd'=>'Stars of David','s'=>'Spikey Stars',
    'a'=>'Hearts (SVG only)','l'=>'horizontal "engraving" lines (SVG only)',
    'w'=>'wavy horizontal "engraving" lines (SVG only)');
$avg_algs = array(
    '1' => 'single cell',
    '2' => 'cell + NSEW neighbors',
    '3' => 'cell + all 8 surrounding cells'
);
if (!$options || isset($options['h']) || !isset($options['s']) || empty($options['s']))
{
    echo "Usage: halftoner.php -s [source image]\n";
    echo "options:  -v -j -e -i -a [1-3] -l [thresh] -p [percent coverage] -d [dots] -S [shape]\n";
    echo " where:\n";
    echo "   -h is help (you're reading it!)\n";
    echo "   -v is verbose\n";
    echo "   -p is percent coverage (100% means circles touch)\n";
    echo "   -d is number of dots across\n";
    echo "   -j outputs preview JPGs in addition to SVG image\n";
    echo "   -e equalize histogram first\n";
    echo "   -i invert luminosity\n";
    echo "   -l minimum luminosity (0-100%) to register\n";
    echo "   -a averaging algorithm:\n";
    foreach($avg_algs as $akey=>$aname)
        echo "     $akey : $aname\n";
    echo "   -S determines \"dot\" or output shape:\n";
    foreach($shapes as $skey=>$sname)
        echo "     $skey : $sname\n";
    die;
}

$set = array();
foreach($defaults as $def=>$def_opts)
{
    if (is_bool($def_opts['val']))
        $set[$def]=isset($options[$def_opts['opt']]);
    else
        $set[$def] = (isset($options[$def_opts['opt']]) ? $options[$def_opts['opt']] : $def_opts['val']);
    if ($set['verbose'])
    {
        $val = $set[$def];
        if (isset($def_opts['var']))
            $val = eval($def_opts['var']);
        if (is_bool($val))
            echo (!$val?'Not ':'').$def_opts['desc']."\n";
        else
        {
            echo str_replace('[val]', $val, $def_opts['desc']) . "\n";
        }
    }
}

$image = imagecreatefromjpeg($set['source']);
$source_imagex = imagesx($image);
$source_imagey = imagesy($image);
if ($set['verbose']) echo "-> resizing to width {$set['dots']}\n";

$block = $source_imagex / $set['dots'];

$orig_rat = $source_imagex / $source_imagey;

$dy = sqrt($block * $block - ($block * $block / 4));
$dyf = $dy / $block;

$proc = imagescale($image, $set['dots'], $set['dots'] / ($orig_rat * $dyf));
$proc_imagex = imagesx($proc);
$proc_imagey = imagesy($proc);

if ($set['jpg'])
{
    $half = imagecreatetruecolor($source_imagex, floor($source_imagey / $dyf));
    $white = imagecolorallocate($half, 255, 255, 255);
    // Draw a white rectangle
    imagefilledrectangle($half, 0, 0, $source_imagex, floor($source_imagey / $dyf), $white);
    $ccol = imagecolorallocate($half, 0, 0, 0);
}


if ($set['verbose']) echo "-> building new image\n";

$out = fopen(spec($options['s'], '', $set, 'svg'), 'w');
fwrite($out, "<svg version=\"1.1\"
     baseProfile=\"full\"
     width=\"$source_imagex\" height=\"" . floor($source_imagey / $dyf) . "\"
     xmlns=\"http://www.w3.org/2000/svg\">\n");


$lmin = 1;
$lmax = 0;
$roff = 0;
$ratio = 0;
$length = 0;
$so = ($set['avg_alg'] >= 2);
$to = ($set['avg_alg'] == 3);
$lthresh = $set['lumin_thresh']/100;

$lum_row_idx = 0;
$data = array();
for ($row = 0; $row < $proc_imagey; $row += 2)
{
    $data[$lum_row_idx] = array();
    $lum_col_idx = 0;
    $roff = 1 - $roff;
    for ($col = $roff; $col < $proc_imagex; $col += 2)
    {
        $blum = lumAt($proc, $col, $row, $proc_imagex, $proc_imagey, $so, $to);
        if ($set['invert'])
            $blum = 1 - $blum;

        $data[$lum_row_idx][$lum_col_idx] = $blum;
        $lum_col_idx++;
        if ($blum < $lmin)
            $lmin = $blum;
        else if ($blum > $lmax)
            $lmax = $blum;
    }
    $lum_row_idx++;
}

$ratio = 1 / ($lmax - $lmin);
$roff = 0;

if ($set['shape'] == 'w' || $set['shape'] == 'l')
{
    for ($row = 0; $row < $lum_row_idx; $row++)
    {
        $roff = 1 - $roff;
        $row_mag = array(array(),array());
        $cy = $row * 2 * $dy;
        $open = false;
        for ($col = 0; $col < $lum_col_idx; $col++)
        {
            $blum = $data[$row][$col];

            if ($set['equalize'])
                $blum = ($blum - $lmin) * $ratio;

            $lum = min($set['percent']/100, $blum);
            $val = $block * $lum;

            if ($lum > $lthresh)
            {
                $row_mag[0][] = floor($col * 2 * $block + ($roff * $block));
                $row_mag[1][] = $val*0.85;
                $open = true;
            }
            if ($lum <= $lthresh || $col == $lum_col_idx-1)
            {
                if ($open)
                {
                    $num = count($row_mag[0]);
                    if ($num > 1)
                    {
                        if ($set['shape'] == 'w')
                        {
                            fwrite($out, "<path d=\"M " . ($row_mag[0][0]) . ' ' . round($cy - $row_mag[1][0], 2));
                            for ($i = 1; $i < $num; $i++)
                            {
                                fwrite($out, ' Q ' . round(($row_mag[0][$i] + $row_mag[0][$i - 1]) / 2, 2) .
                                    ' ' . round($cy - ($row_mag[1][$i] + $row_mag[1][$i - 1]) / 1.6, 2) . ' ');
                                fwrite($out, ($row_mag[0][$i]) . ' ' . round($cy - $row_mag[1][$i], 2));
                            }

                            fwrite($out, ' Q ' . ($row_mag[0][$num - 1] + $block / 1.5) .
                                ' ' . round($cy, 2) . ' ');
                            fwrite($out, ($row_mag[0][$num - 1]) . ' ' . round($cy + $row_mag[1][$num - 1], 2));

                            for ($i = $num - 2; $i >= 0; $i--)
                            {
                                fwrite($out, ' Q ' . round(($row_mag[0][$i] + $row_mag[0][$i + 1]) / 2, 2) .
                                    ' ' . round($cy + ($row_mag[1][$i] + $row_mag[1][$i + 1]) / 1.6, 2) . ' ');
                                fwrite($out, ($row_mag[0][$i]) . ' ' . round($cy + $row_mag[1][$i], 2));
                            }

                            fwrite($out, ' Q ' . ($row_mag[0][0] - $block / 1.5) .
                                ' ' . round($cy, 2) . ' ');
                            fwrite($out, ($row_mag[0][0]) . ' ' . round($cy - $row_mag[1][0], 2));

                            fwrite($out, "\" fill=\"black\" />\n");
                        }
                        else if ($set['shape'] == 'l')
                        {
                            fwrite($out, "<polygon points=\"");
                            for ($i = 0; $i < $num; $i++)
                            {
                                fwrite($out, ($row_mag[0][$i]) . ',' . round($cy - $row_mag[1][$i], 2) . ',');
                            }
                            for ($i = 0; $i < $num; $i++)
                            {
                                fwrite($out, ($row_mag[0][$num - $i - 1]) . ',' . round($cy + $row_mag[1][$num - $i - 1], 2));
                                if ($i < $num - 1)
                                    fwrite($out, ',');
                            }
                            fwrite($out, "\" fill=\"black\" />\n");
                        }
                    }
                    $row_mag = array(array(),array());
                }
                $open = false;
            }
        }
    }
}
else
{
    for ($row = 0; $row < $lum_row_idx; $row++)
    {
        $roff = 1 - $roff;
        for ($col = 0; $col < $lum_col_idx; $col++)
        {
            $blum = $data[$row][$col];

            if ($set['equalize'])
                $blum = ($blum - $lmin) * $ratio;

            $lum = min($set['percent']/100, $blum);

            $val = $block * $lum;
            if ($lum > $lthresh)
            {
                $cx = $col * 2 * $block + ($roff * $block);
                $cy = $row * 2 * $dy;
                if ($set['shape'] == 'c')
                {
                    if ($set['jpg'])
                        imagefilledellipse($half, $cx, $cy, $val * 2, $val * 2, $ccol);

                    fwrite($out, "<circle cx=\"$cx\" cy=\"$cy\" r=\"" . $val .
                        "\" fill=\"black\" />\n");
                    $length += (3.1415 * $val);
                }
                else if ($set['shape'] == 'h')
                {
                    $pts = top_pointy_hex($cx, $cy, $val);
                    if ($set['jpg'])
                        imagefilledpolygon($half, $pts, count($pts) / 2, $ccol);
                    fwrite($out, "<polygon points=\"" . implode(',', $pts) . "\" fill=\"black\" />\n");
                    for ($i = 3; $i < count($pts); $i += 2)
                    {
                        $length += sqrt(
                            pow($pts[$i - 1] - $pts[$i - 3], 2) +
                            pow($pts[$i] - $pts[$i - 2], 2));
                    }
                }
                else if ($set['shape'] == 'd' || $set['shape'] == 's')
                {
                    $pts = top_pointy_star($cx, $cy, $val, ($set['shape'] == 'd' ? 0.70 : 0.45));
                    if ($set['jpg'])
                        imagefilledpolygon($half, $pts, count($pts) / 2, $ccol);
                    fwrite($out, "<polygon points=\"" . implode(',', $pts) . "\" fill=\"black\" />\n");
                    for ($i = 3; $i < count($pts); $i += 2)
                    {
                        $length += sqrt(
                            pow($pts[$i - 1] - $pts[$i - 3], 2) +
                            pow($pts[$i] - $pts[$i - 2], 2));
                    }
                }
                else if ($set['shape'] == 'a')
                {
                    $svgpts = heart($cx, $cy, $val);
                    $nonsvgpts = array_values(array_filter($svgpts, 'is_numeric'));
                    if ($set['jpg'])
                        imagefilledpolygon($half, $nonsvgpts, count($nonsvgpts) / 2, $ccol);
                    fwrite($out, "<path d=\"" . implode(' ', $svgpts) . "\" fill=\"black\" />\n");
                }

                if ($set['verbose'] && false)
                {
                    fwrite($out, '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $block .
                        '" style="fill:none;stroke:red;stroke-width:1;" />');
                    fwrite($out, '<text x="' . ($cx - $block) . '" y="' . $cy . '" font-family="Verdana" font-size="12" stroke="none" fill="red">');
                    fwrite($out, round($lum, 2));
                    fwrite($out, '</text>');
                }
            }
        }
    }
}
if ($set['verbose']) echo "-> lmin $lmin lmax $lmax pct {$set['percent']}\n";
if ($set['verbose']) echo "-> writing jpg\n";
if ($set['verbose']) echo "--> Total length: $length\n";
if ($set['jpg'])
{
    imagejpeg($half, spec($options['s'], 'halftone', $set,'jpg'));
    imagefilter($half, IMG_FILTER_NEGATE);
    imagejpeg($half, spec($options['s'], 'halftone_inv', $set, 'jpg'));
}

if ($set['verbose']) echo "-> writing svg: ".spec($options['s'], '', $set, 'svg')."\n" ;
fwrite($out, "</svg>\n");
fclose($out);

function heart($x, $y, $mr)
{
    $pts = array(
        'M',
        $x, $y + 1 * $mr, 'C',
        $x - 0.0031645569620253 * $mr, $y + 1 * $mr, ',',
        $x - 0.92088607594937 * $mr, $y + 0.42088607594937 * $mr, ',',
        $x - 0.92088607594937 * $mr, $y - 0.15822784810127 * $mr, 'C',
        $x - 0.92088607594937 * $mr, $y - 0.87658227848101 * $mr, ',',
        $x - 0.094936708860759 * $mr, $y - 0.78164556962025 * $mr, ',',
        $x, $y - 0.5126582278481 * $mr, 'C',
        $x + 0.094936708860759 * $mr, $y - 0.78164556962025 * $mr, ',',
        $x + 0.92088607594937 * $mr, $y - 0.87658227848101 * $mr, ',',
        $x + 0.92088607594937 * $mr, $y - 0.15822784810127 * $mr, 'C',
        $x + 0.92088607594937 * $mr, $y + 0.42088607594937 * $mr, ',',
        $x + 0.0031645569620253 * $mr, $y + 1 * $mr, ',',
        $x, $y + 1 * $mr);
    return $pts;
}

function top_pointy_hex($x, $y, $mr)
{
    $pts = array();
    $pts[] = $x;
    $pts[] = $y - $mr;

    $pts[] = $x + $mr * 0.866025;
    $pts[] = $y - $mr / 2;

    $pts[] = $x + $mr * 0.866025;
    $pts[] = $y + $mr / 2;

    $pts[] = $x;
    $pts[] = $y + $mr;

    $pts[] = $x - $mr * 0.866025;
    $pts[] = $y + $mr / 2;

    $pts[] = $x - $mr * 0.866025;
    $pts[] = $y - $mr / 2;

    // close it
    $pts[] = $x;
    $pts[] = $y - $mr;

    return $pts;
}

function top_pointy_star($x, $y, $mr, $f)
{
    $pts = array();
    $pts[] = $x;
    $pts[] = $y - $mr;

    $pts[] = $x + $mr * 0.5 * $f;
    $pts[] = $y - $mr * 0.866025 * $f;

    $pts[] = $x + $mr * 0.866025;
    $pts[] = $y - $mr / 2;

    $pts[] = $x + $mr * $f;
    $pts[] = $y;

    $pts[] = $x + $mr * 0.866025;
    $pts[] = $y + $mr / 2;

    $pts[] = $x + $mr * 0.5 * $f;
    $pts[] = $y + $mr * 0.866025 * $f;

    $pts[] = $x;
    $pts[] = $y + $mr;

    $pts[] = $x - $mr * 0.5 * $f;
    $pts[] = $y + $mr * 0.866025 * $f;

    $pts[] = $x - $mr * 0.866025;
    $pts[] = $y + $mr / 2;

    $pts[] = $x - $mr * $f;
    $pts[] = $y;

    $pts[] = $x - $mr * 0.866025;
    $pts[] = $y - $mr / 2;

    $pts[] = $x - $mr * 0.5 * $f;
    $pts[] = $y - $mr * 0.866025 * $f;

    $pts[] = $x;
    $pts[] = $y - $mr;

    return $pts;
}

function lum($rgb)
{
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return 1 - (0.299 * $r / 255 + 0.587 * $g / 255 + 0.114 * $b / 255);
}

function lumAt($proc, $col, $row, $proc_imagex, $proc_imagey, $so = true, $to = true)
{
    $rgb = imagecolorat($proc, $col, $row);
    $padd = 0;
    $sadd = 0;
    $padds = 0;
    $sadds = 0;
    $div = 1 + ($so ? 1 : 0) + ($to ? 1 : 0);

    if ($so && $row > 0)
    {
        $rgbu = imagecolorat($proc, $col, $row - 1);
        $padd += lum($rgbu);
        $padds += 1;
        if ($to && $col > 0)
        {
            $rgbnw = imagecolorat($proc, $col - 1, $row - 1);
            $sadd += lum($rgbnw);
            $sadds += 1;
        }
        if ($to && $col < $proc_imagex - 1)
        {
            $rgbsw = imagecolorat($proc, $col + 1, $row - 1);
            $sadd += lum($rgbsw);
            $sadds += 1;
        }
    }
    if ($so && $row < $proc_imagey - 1)
    {
        $rgbd = imagecolorat($proc, $col, $row + 1);
        $padd += lum($rgbd);
        $padds += 1;
        if ($to && $col > 0)
        {
            $rgbne = imagecolorat($proc, $col - 1, $row + 1);
            $sadd += lum($rgbne);
            $sadds += 1;
        }
        if ($to && $col < $proc_imagex - 1)
        {
            $rgbse = imagecolorat($proc, $col + 1, $row + 1);
            $sadd += lum($rgbse);
            $sadds += 1;
        }
    }
    if ($so && $col > 0)
    {
        $rgbl = imagecolorat($proc, $col - 1, $row);
        $padd += lum($rgbl);
        $padds += 1;
    }
    if ($so && $col < $proc_imagex - 1)
    {
        $rgbr = imagecolorat($proc, $col + 1, $row);
        $padd += lum($rgbr);
        $padds += 1;
    }

    return (lum($rgb) + ($padds > 0 ? $padd / $padds : 0) + ($sadds > 0 ? $sadd / $sadds : 0)) / $div;
}

function spec($src, $suffix, $settings, $ext)
{
    $out = '';
    $bits = pathinfo($src);
    $out = $bits['filename'] . (!empty($suffix) ? '_' . $suffix : '');
    $out .= '-d' . $settings['dots'] . '-s'.$settings['shape'];
    $out .= '-p' . $settings['percent'];
    $out .= '-a' . $settings['avg_alg'];
    $out .= '-t' . $settings['lumin_thresh'];
    $out .= ($settings['equalize'] ? '-e' : '');
    $out .= ($settings['invert'] ? '-i' : '');
    return $out . '.' . $ext;
}
