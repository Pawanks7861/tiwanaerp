<?php

namespace App\Services\Files;

/**
 * Reads a text DXF as data and emits a script-free SVG of LINE and CIRCLE entities.
 * Binary DXF and anything that is not a DXF return null.
 */
class DxfSvgEncoder
{
    private const MAX_BYTES = 2_000_000;

    private const MAX_ENTITIES = 2000;

    public function fromPath(string $absolutePath): ?string
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }

        $raw = fread($handle, self::MAX_BYTES + 1);
        fclose($handle);
        if (! is_string($raw) || $raw === '' || str_contains(substr($raw, 0, 64), "\0")) {
            return null;
        }

        $sample = ltrim(substr($raw, 0, self::MAX_BYTES));
        if (! str_starts_with($sample, '0')) {
            return null;
        }

        $lines = preg_split("/\r\n|\n|\r/", substr($raw, 0, self::MAX_BYTES)) ?: [];
        $entities = [];
        $current = null;

        $count = count($lines);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $code = trim($lines[$i]);
            $value = trim($lines[$i + 1]);
            if ($code !== '0') {
                if (is_array($current)) {
                    $current[$code] = $value;
                }

                continue;
            }

            if (is_array($current) && $this->keep($current)) {
                $entities[] = $current;
                if (count($entities) >= self::MAX_ENTITIES) {
                    break;
                }
            }

            $current = ['type' => strtoupper($value)];
        }

        if (is_array($current) && $this->keep($current) && count($entities) < self::MAX_ENTITIES) {
            $entities[] = $current;
        }

        return $entities === [] ? null : $this->svg($entities);
    }

    /**
     * @param  array<string, string>  $entity
     */
    private function keep(array $entity): bool
    {
        return in_array($entity['type'] ?? '', ['LINE', 'CIRCLE'], true);
    }

    /**
     * @param  list<array<string, string>>  $entities
     */
    private function svg(array $entities): ?string
    {
        $shapes = [];
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;

        foreach ($entities as $entity) {
            if ($entity['type'] === 'LINE') {
                $x1 = $this->number($entity['10'] ?? null);
                $y1 = $this->number($entity['20'] ?? null);
                $x2 = $this->number($entity['11'] ?? null);
                $y2 = $this->number($entity['21'] ?? null);
                if ($x1 === null || $y1 === null || $x2 === null || $y2 === null) {
                    continue;
                }
                $shapes[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>', $x1, -$y1, $x2, -$y2);
                $minX = min($minX, $x1, $x2);
                $maxX = max($maxX, $x1, $x2);
                $minY = min($minY, -$y1, -$y2);
                $maxY = max($maxY, -$y1, -$y2);
            }

            if ($entity['type'] === 'CIRCLE') {
                $cx = $this->number($entity['10'] ?? null);
                $cy = $this->number($entity['20'] ?? null);
                $radius = $this->number($entity['40'] ?? null);
                if ($cx === null || $cy === null || $radius === null || $radius < 0) {
                    continue;
                }
                $shapes[] = sprintf('<circle cx="%s" cy="%s" r="%s"/>', $cx, -$cy, $radius);
                $minX = min($minX, $cx - $radius);
                $maxX = max($maxX, $cx + $radius);
                $minY = min($minY, -$cy - $radius);
                $maxY = max($maxY, -$cy + $radius);
            }
        }

        if ($shapes === [] || $minX === INF) {
            return null;
        }

        $width = max(1, $maxX - $minX);
        $height = max(1, $maxY - $minY);
        $pad = max($width, $height) * 0.05;
        $body = implode('', $shapes);

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" fill="none" stroke="#0f172a" stroke-width="%s">%s</svg>',
            $this->number((string) ($minX - $pad)),
            $this->number((string) ($minY - $pad)),
            $this->number((string) ($width + $pad * 2)),
            $this->number((string) ($height + $pad * 2)),
            $this->number((string) (max($width, $height) / 400)),
            $body,
        );
    }

    private function number(?string $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number)) {
            return null;
        }

        return rtrim(rtrim(sprintf('%.4F', $number), '0'), '.');
    }
}
