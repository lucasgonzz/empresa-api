<?php

namespace App\Services\Filter;

use App\Models\FilterHistory;
use Illuminate\Support\Facades\Log;

class FilterHistoryService
{
    public static function log_action($data) {

        $used_filters_text = self::filters_to_text($data['used_filters']);

        Log::info('used_filters:');
        Log::info($data['used_filters']);

        Log::info('used_filters_text:');
        Log::info($used_filters_text);

        return FilterHistory::create([
            'user_id'             => $data['user_id'],
            'auth_user_id'        => $data['auth_user_id'],
            'action'              => $data['action'],
            'model_name'          => $data['model_name'],
            'filtrados_count'     => $data['filtrados_count'],
            'afectados_count'     => $data['afectados_count'],
            'used_filters'        => json_encode($data['used_filters']),
            'used_filters_text'   => $used_filters_text,
            // 'extra_data'          => empty($data['extra_data']) ? [] : $data['extra_data'],
        ]);
    }

    private static function filters_to_text(array $used_filters): ?string
    {
        if (empty($used_filters)) {
            return null;
        }

        $lines = [];
        foreach ($used_filters as $f) {
            $key = $f['key'] ?? '-';
            $op = $f['operator'] ?? '=';
            $value = array_key_exists('value', $f) ? $f['value'] : null;

            if (is_array($value)) {
                $value = json_encode($value);
            }

            $lines[] = trim($key.' '.$op.' '.(string)$value);
        }

        return implode(' | ', $lines);
    }
}
