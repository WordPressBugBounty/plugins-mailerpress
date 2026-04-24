<?php

namespace MailerPress\Core\Workflows\Services;

class ConditionEvaluator
{
    /**
     * Evaluate a condition structure
     * 
     * @param array|null $condition Condition array or null for "no condition"
     * @param int $userId User ID to evaluate against
     * @param array $context Additional context data
     * @return bool True if condition is met (or no condition), false otherwise
     */
    public function evaluate(?array $condition, int $userId, array $context = []): bool
    {
        // No condition means always true (trigger/workflow should proceed)
        if ($condition === null || empty($condition)) {
            return true;
        }

        // Validate condition structure
        if (!is_array($condition)) {
            return false;
        }

        $operator = $condition['operator'] ?? 'AND';
        $rules = $condition['rules'] ?? [];

        // Empty rules array means no condition (always true)
        if (empty($rules) || !is_array($rules)) {
            return true;
        }

        $results = [];
        $ruleIndex = 0;

        foreach ($rules as $rule) {
            $ruleIndex++;
            // Skip invalid rules
            if (!is_array($rule) || empty($rule)) {
                continue;
            }

            // Handle nested condition groups
            if (isset($rule['rules']) && is_array($rule['rules'])) {
                $nestedResult = $this->evaluate($rule, $userId, $context);
                // Ensure result is boolean
                $results[] = (bool) $nestedResult;
            } else {
                // Validate rule before evaluating
                if ($this->isValidRule($rule)) {
                    $field = $rule['field'] ?? $rule['type'] ?? 'unknown';
                    $ruleOperator = $rule['operator'] ?? '==';
                    $ruleResult = $this->evaluateRule($rule, $userId, $context);
                    // Ensure result is boolean
                    $results[] = (bool) $ruleResult;
                } else {
                    // Invalid rule - log for debugging
                    // For invalid rules, treat as false to ensure AND/OR logic works correctly
                    // This prevents invalid rules from being silently ignored
                    $results[] = false;
                }
            }
        }

        // If no valid rules were evaluated, treat as "no condition" (true)
        if (empty($results)) {
            return true;
        }

        // Evaluate based on operator
        $final = $operator === 'AND'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);

        return $final;
    }

    /**
     * Validate if a rule has the minimum required structure
     * 
     * @param array $rule Rule to validate
     * @return bool True if rule is valid, false otherwise
     */
    private function isValidRule(array $rule): bool
    {
        // Rule must be an array
        if (!is_array($rule) || empty($rule)) {
            return false;
        }

        // If rule has a 'type', it might be valid even without 'field'
        if (isset($rule['type']) && !empty($rule['type'])) {
            // For user_meta_* types, check if meta_key is present
            if (str_starts_with($rule['type'], 'user_meta_')) {
                return !empty($rule['meta_key']);
            }
            // Other types might be valid
            return true;
        }

        // Standard rules must have a field
        if (empty($rule['field'])) {
            return false;
        }

        // Operator is optional (defaults to '==')
        // Value is optional for some operators like 'empty', 'not_empty'
        return true;
    }

    private function evaluateRule(array $rule, int $userId, array $context): bool
    {
        // Validate rule structure first
        if (!$this->isValidRule($rule)) {
            return false;
        }

        $type = $rule['type'] ?? null;

        // 1️⃣ Support for "type" based shortcuts
        if ($type && str_starts_with($type, 'user_meta_')) {
            $metaKey = $rule['meta_key'] ?? '';
            $value = $rule['value'] ?? '';

            // Validate meta_key is present
            if (empty($metaKey)) {
                return false;
            }

            $metaValue = get_user_meta($userId, $metaKey, true);

            return match ($type) {
                'user_meta_contains' => is_string($metaValue) && str_contains($metaValue, $value),
                'user_meta_not_contains' => is_string($metaValue) && !str_contains($metaValue, $value),
                'user_meta_equals' => $metaValue == $value,
                'user_meta_not_equals' => $metaValue != $value,
                'user_meta_empty' => empty($metaValue),
                'user_meta_not_empty' => !empty($metaValue),
                default => false,
            };
        }

        // 2️⃣ Fallback to standard field/operator rules
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? '==';
        $value = $rule['value'] ?? null;

        // Validate field is present
        if (empty($field)) {
            return false;
        }

        // Allow third-party filters to handle the rule evaluation
        if (function_exists('apply_filters')) {
            $maybe = apply_filters('mailerpress/condition/evaluate_rule', null, $rule, $userId, $context);
            if (is_bool($maybe)) {
                return $maybe;
            }
        }

        $fieldValue = $this->getFieldValue($field, $userId, $context);

        // Handle operators that don't require a value
        if (in_array($operator, ['empty', 'not_empty'], true)) {
            $result = match ($operator) {
                'empty' => empty($fieldValue),
                'not_empty' => !empty($fieldValue),
                default => false,
            };
            return $result;
        }

        // For other operators, value is required
        if ($value === null && !in_array($operator, ['empty', 'not_empty'], true)) {
            // Value is missing but operator requires it - this is an invalid rule
            return false;
        }
        
        // Handle null fieldValue explicitly for better debugging
        if ($fieldValue === null && !in_array($operator, ['empty', 'not_empty', '==', '!='], true)) {
            // Field value is null and operator doesn't support null - return false
            return false;
        }

        // Normalize boolean values for comparison
        // Handle both string 'true'/'false' and boolean true/false
        $normalizedFieldValue = $fieldValue;
        $normalizedValue = $value;

        if (is_bool($fieldValue)) {
            $normalizedFieldValue = $fieldValue ? 'true' : 'false';
        } elseif ($fieldValue === 'true' || $fieldValue === true || $fieldValue === 1 || $fieldValue === '1') {
            $normalizedFieldValue = 'true';
        } elseif ($fieldValue === 'false' || $fieldValue === false || $fieldValue === 0 || $fieldValue === '0') {
            $normalizedFieldValue = 'false';
        }

        if (is_bool($value)) {
            $normalizedValue = $value ? 'true' : 'false';
        } elseif ($value === 'true' || $value === true || $value === 1 || $value === '1') {
            $normalizedValue = 'true';
        } elseif ($value === 'false' || $value === false || $value === 0 || $value === '0') {
            $normalizedValue = 'false';
        }

        $result = match ($operator) {
            '==' => $normalizedFieldValue == $normalizedValue,
            '!=' => $normalizedFieldValue != $normalizedValue,
            '>' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue > $value,
            '<' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue < $value,
            '>=' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue >= $value,
            '<=' => is_numeric($fieldValue) && is_numeric($value) && $fieldValue <= $value,
            'contains' => is_string($fieldValue) && is_string($value) && str_contains($fieldValue, $value),
            'not_contains' => is_string($fieldValue) && is_string($value) && !str_contains($fieldValue, $value),
            'starts_with' => is_string($fieldValue) && is_string($value) && str_starts_with($fieldValue, $value),
            'ends_with' => is_string($fieldValue) && is_string($value) && str_ends_with($fieldValue, $value),
            'in' => is_array($value) && in_array($fieldValue, $value, true),
            'not_in' => is_array($value) && !in_array($fieldValue, $value, true),
            'empty' => empty($fieldValue),
            'not_empty' => !empty($fieldValue),
            default => false,
        };

        return $result;
    }

    private function getFieldValue(string $field, int $userId, array $context): mixed
    {
        // Let third-parties provide values for custom fields
        if (function_exists('apply_filters')) {
            $provided = apply_filters('mailerpress/condition/get_field_value', null, $field, $userId, $context);
            if ($provided !== null) {
                return $provided;
            }
        }

        // Support for dot notation to access nested webhook data
        // Example: "order.id" or "customer.type" or "data.user.email"
        if (str_contains($field, '.')) {
            $parts = explode('.', $field);
            $value = $context;
            
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } elseif (is_object($value) && isset($value->$part)) {
                    $value = $value->$part;
                } else {
                    // Try to find in webhook_data if available
                    if (isset($context['webhook_data']) && is_array($context['webhook_data'])) {
                        $webhookValue = $context['webhook_data'];
                        foreach ($parts as $webhookPart) {
                            if (is_array($webhookValue) && isset($webhookValue[$webhookPart])) {
                                $webhookValue = $webhookValue[$webhookPart];
                            } elseif (is_object($webhookValue) && isset($webhookValue->$webhookPart)) {
                                $webhookValue = $webhookValue->$webhookPart;
                            } else {
                                return null;
                            }
                        }
                        return is_scalar($webhookValue) ? $webhookValue : json_encode($webhookValue);
                    }
                    return null;
                }
            }
            
            return is_scalar($value) ? $value : json_encode($value);
        }

        // Direct access to context
        if (isset($context[$field])) {
            return $context[$field];
        }

        // Try webhook_data for dynamic access
        if (isset($context['webhook_data']) && is_array($context['webhook_data'])) {
            if (isset($context['webhook_data'][$field])) {
                $value = $context['webhook_data'][$field];
                return is_scalar($value) ? $value : json_encode($value);
            }
        }

        $user = get_userdata($userId);
        if (!$user) {
            return null;
        }

        $value = match ($field) {
            'user_email' => $user->user_email,
            'user_login' => $user->user_login,
            'user_role' => $user->roles[0] ?? '',
            'display_name' => $user->display_name,
            'user_registered' => $user->user_registered,
            default => get_user_meta($userId, $field, true),
        };

        return $value;
    }
}
