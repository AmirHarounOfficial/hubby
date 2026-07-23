<?php

namespace App\Services\Shipping\Barcode;

/**
 * A tiny, dependency-free Code 128-B → SVG encoder (spec 04 §4.9). The packing slip carries a
 * scannable barcode of the shipment reference; Spec 08 warehouse scanning reads it. Kept in-house so
 * we don't pull a barcode composer package just for one glyph.
 */
class Code128
{
    /** Bar/space width patterns, index = symbol value (0..106). Each digit is a module width. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212',
        '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221',
        '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221',
        '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321',
        '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131',
        '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131',
        '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111',
        '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242',
        '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311',
        '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;

    /** Render an ASCII string as a Code 128-B barcode SVG. */
    public function svg(string $data, int $moduleWidth = 2, int $height = 60): string
    {
        $data = preg_replace('/[^\x20-\x7E]/', '', $data); // Code 128-B is printable ASCII
        $codes = [self::START_B];
        $sum = self::START_B;

        $chars = str_split($data);
        foreach ($chars as $i => $ch) {
            $value = ord($ch) - 32;
            $codes[] = $value;
            $sum += $value * ($i + 1);
        }
        $codes[] = $sum % 103; // checksum
        $codes[] = self::STOP;

        $x = 0;
        $rects = '';
        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code];
            $isBar = true;
            foreach (str_split($pattern) as $w) {
                $width = (int) $w * $moduleWidth;
                if ($isBar) {
                    $rects .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="'.$height.'" fill="#000"/>';
                }
                $x += $width;
                $isBar = ! $isBar;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$x.'" height="'.$height.'" viewBox="0 0 '.$x.' '.$height.'">'.$rects.'</svg>';
    }
}
