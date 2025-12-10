<?php

namespace App\Repositories\Concerns;

trait DetectsModelChanges {
    /**
     * Normalize scalar or structured values so they can be compared consistently.
     *
     * @param mixed $value
     * @return string
     */
    protected function normalizeComparisonValue($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string)$value);
    }

    /**
     * Determine whether the provided data differs from the original.
     *
     * @param array $newData
     * @param array $original
     * @param array $ignoredKeys
     * @return bool
     */
    protected function hasMeaningfulChanges(array $newData, array $original, array $ignoredKeys = []) {
        if (empty($original)) {
            return true;
        }

        foreach ($newData as $key => $value) {
            if (in_array($key, $ignoredKeys, true)) {
                continue;
            }

            $left = $this->normalizeComparisonValue($value);
            $right = array_key_exists($key, $original)
                ? $this->normalizeComparisonValue($original[$key])
                : '';

            if ($left !== $right) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a list of identifiers (typically from multi-select inputs) for comparison.
     *
     * @param iterable $values
     * @param mixed $nullToken Value that should be treated as null before normalization.
     * @return array
     */
    protected function normalizeSelectionList($values, $nullToken = null) {
        $normalized = [];

        foreach ((array)$values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($nullToken !== null && $value == $nullToken) {
                $value = 0;
            }

            $normalized[] = (string)$value;
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * Compare two selection lists after normalization.
     *
     * @param iterable $incoming
     * @param iterable $existing
     * @param mixed $nullToken
     * @return bool True when lists differ.
     */
    protected function selectionListHasChanges($incoming, $existing, $nullToken = null) {
        return $this->normalizeSelectionList($incoming, $nullToken) !== $this->normalizeSelectionList($existing, $nullToken);
    }
}
