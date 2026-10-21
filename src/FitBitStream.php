<?php
namespace adriangibbons;

/**
 * @internal Reads a field's value (one value, or an array of them) as a little-endian bit stream,
 * as FIT components are packed: the first component in the lowest bits of the first element.
 */
final class FitBitStream
{
    private array $values;
    private int $element_bits;
    private int $index = 0;
    private int $bit = 0;
    private int $available;

    /**
     * @param int|float|array $value  a field's raw value(s); a null (invalid) element reads as all ones
     * @param int       $element_bits bits per element (the base type's size)
     */
    public function __construct(int|float|array $value, int $element_bits)
    {
        $mask = $element_bits >= 63 ? -1 : (1 << $element_bits) - 1;
        $this->values = array_map(fn($v) => $v === null ? $mask : ((int) $v & $mask), is_array($value) ? array_values($value) : [$value]);
        $this->element_bits = $element_bits;
        $this->available = count($this->values) * $element_bits;
    }

    public function available(): int
    {
        return $this->available;
    }

    public function read(int $bits): int
    {
        $out = 0;
        $shift = 0;
        while ($bits > 0) {
            $take = min($this->element_bits - $this->bit, $bits);
            $out |= (($this->values[$this->index] >> $this->bit) & ((1 << $take) - 1)) << $shift;
            $shift += $take;
            $bits -= $take;
            $this->available -= $take;
            $this->bit += $take;
            if ($this->bit === $this->element_bits) {
                $this->index++;
                $this->bit = 0;
            }
        }

        return $out;
    }
}
