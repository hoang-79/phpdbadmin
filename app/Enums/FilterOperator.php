<?php

namespace App\Enums;

/**
 * Operatoren des geführten Filters im Datenraster (D-02). Werte werden immer gebunden, nie eingesetzt; «enthält»
 * und Verwandte ohne Rücksicht auf Gross- und Kleinschreibung, wo der Treiber es zulässt.
 */
enum FilterOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case LessThan = 'lt';
    case LessOrEqual = 'lte';
    case GreaterThan = 'gt';
    case GreaterOrEqual = 'gte';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts';
    case EndsWith = 'ends';
    case IsNull = 'null';
    case IsNotNull = 'not_null';

    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsNull, self::IsNotNull], true);
    }

    /**
     * Zeichen im Filter-Chip: «status = offen», «created_at ≥ 21.10.2026».
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Equals => '=',
            self::NotEquals => '≠',
            self::LessThan => '<',
            self::LessOrEqual => '≤',
            self::GreaterThan => '>',
            self::GreaterOrEqual => '≥',
            self::Contains => __('enthält'),
            self::NotContains => __('enthält nicht'),
            self::StartsWith => __('beginnt mit'),
            self::EndsWith => __('endet mit'),
            self::IsNull => __('ist NULL'),
            self::IsNotNull => __('ist nicht NULL'),
        };
    }
}
