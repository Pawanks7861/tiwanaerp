<?php

namespace App\Services\Files\Cad;

/**
 * Rejects empty or non-drawing converter output before it is stored.
 */
class CadPreviewValidator
{
    public function acceptable(string $path, string $format): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size === false || $size < (int) config('previews.cad.min_output_bytes', 40)) {
            return false;
        }

        $head = $this->head($path, 512_000);
        if ($format === 'pdf') {
            return str_starts_with($head, '%PDF');
        }

        return str_contains($head, '<svg') && $this->containsDrawable($head);
    }

    public function containsDrawable(string $svg): bool
    {
        return preg_match('/<(line|path|circle|polyline|polygon|text|rect|ellipse|image|use)\b/i', $svg) === 1;
    }

    /**
     * LibreDWG emits hairline strokes (often 0.1px) that disappear when the drawing is fitted
     * to the viewer. Raise only strokes thinner than the drawing size so existing thick lines stay.
     */
    public function visibleStrokes(string $svg): string
    {
        if (! preg_match('/viewBox="([^"]+)"/', $svg, $match)) {
            return $svg;
        }

        $parts = preg_split('/[\s,]+/', trim($match[1])) ?: [];
        if (count($parts) < 4) {
            return $svg;
        }

        $minimum = max((float) $parts[2], (float) $parts[3]) / 250;
        if ($minimum < 0.2) {
            $minimum = 0.2;
        }

        $normalized = preg_replace_callback('/stroke-width:\s*([0-9.]+)(px)?/i', function (array $stroke) use ($minimum): string {
            $width = (float) $stroke[1];
            $unit = $stroke[2] ?? '';

            return 'stroke-width:'.($width < $minimum ? $minimum : $width).$unit;
        }, $svg);

        return is_string($normalized) ? $normalized : $svg;
    }

    /**
     * LibreDWG's viewBox often stops short of the geometry. Expand it to the drawn coordinates
     * so the viewer shows the whole drawing instead of clipping the border.
     */
    public function fitCanvas(string $svg): string
    {
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        $note = function (float $x, float $y) use (&$minX, &$minY, &$maxX, &$maxY): void {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        };

        if (preg_match_all('/<circle\b[^>]*>/i', $svg, $circles)) {
            foreach ($circles[0] as $tag) {
                $cx = $this->attr($tag, 'cx');
                $cy = $this->attr($tag, 'cy');
                $radius = $this->attr($tag, 'r') ?? 0.0;
                if ($cx !== null && $cy !== null) {
                    $note($cx - $radius, $cy - $radius);
                    $note($cx + $radius, $cy + $radius);
                }
            }
        }

        if (preg_match_all('/<text\b[^>]*>/i', $svg, $texts)) {
            foreach ($texts[0] as $tag) {
                $x = $this->attr($tag, 'x');
                $y = $this->attr($tag, 'y');
                $size = $this->attr($tag, 'font-size') ?? 0.0;
                if ($x !== null && $y !== null) {
                    $note($x, $y - $size);
                    $note($x, $y);
                }
            }
        }

        if (preg_match_all('/\bd="([^"]+)"/', $svg, $paths)) {
            foreach ($paths[1] as $path) {
                $this->pathBounds($path, $note);
            }
        }

        if (! is_finite($minX) || ! is_finite($minY)) {
            return $svg;
        }

        $padX = max(4, ($maxX - $minX) * 0.04);
        $padY = max(4, ($maxY - $minY) * 0.04);
        $view = sprintf(
            'viewBox="%s %s %s %s"',
            $this->num($minX - $padX),
            $this->num($minY - $padY),
            $this->num(max(1, ($maxX + $padX) - ($minX - $padX))),
            $this->num(max(1, ($maxY + $padY) - ($minY - $padY))),
        );

        if (preg_match('/viewBox="[^"]*"/', $svg) === 1) {
            $fitted = preg_replace('/viewBox="[^"]*"/', $view, $svg, 1);

            return is_string($fitted) ? $fitted : $svg;
        }

        $fitted = preg_replace('/<svg\b/', '<svg '.$view, $svg, 1);

        return is_string($fitted) ? $fitted : $svg;
    }

    private function attr(string $tag, string $name): ?float
    {
        if (preg_match('/'.preg_quote($name, '/').'="(-?\d*\.?\d+(?:[eE][-+]?\d+)?)"/', $tag, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    /**
     * @param  callable(float, float): void  $note
     */
    private function pathBounds(string $path, callable $note): void
    {
        preg_match_all('/[MmLlHhVvCcSsQqTtAaZz]|-?\d*\.?\d+(?:[eE][-+]?\d+)?/', $path, $tokens);
        $items = $tokens[0];
        $command = 'M';
        $index = 0;
        $x = 0.0;
        $y = 0.0;

        while ($index < count($items)) {
            if (preg_match('/^[A-Za-z]$/', $items[$index]) === 1) {
                $command = $items[$index];
                $index++;
                if (strtoupper($command) === 'Z') {
                    continue;
                }
            }

            $count = match (strtoupper($command)) {
                'H', 'V' => 1,
                'S', 'Q' => 4,
                'C' => 6,
                'A' => 7,
                default => 2,
            };
            if ($index + $count > count($items)) {
                break;
            }

            $args = array_map(floatval(...), array_slice($items, $index, $count));
            $index += $count;
            $relative = $command === strtolower($command);
            $upper = strtoupper($command);

            if ($upper === 'H') {
                $x = $relative ? $x + $args[0] : $args[0];
                $note($x, $y);
            } elseif ($upper === 'V') {
                $y = $relative ? $y + $args[0] : $args[0];
                $note($x, $y);
            } elseif ($upper === 'A') {
                $x = $relative ? $x + $args[5] : $args[5];
                $y = $relative ? $y + $args[6] : $args[6];
                $note($x, $y);
            } else {
                for ($point = 0; $point < count($args) - 1; $point += 2) {
                    $note($relative ? $x + $args[$point] : $args[$point], $relative ? $y + $args[$point + 1] : $args[$point + 1]);
                }
                $x = $relative ? $x + $args[count($args) - 2] : $args[count($args) - 2];
                $y = $relative ? $y + $args[count($args) - 1] : $args[count($args) - 1];
            }

            if ($command === 'M') {
                $command = 'L';
            } elseif ($command === 'm') {
                $command = 'l';
            }
        }
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
    }

    private function head(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $raw = fread($handle, $bytes);
        fclose($handle);

        return is_string($raw) ? $raw : '';
    }
}
