<?php

namespace app\model;

use support\Model;

class Option extends Model
{
    protected $table = 'options';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /**
     * 获取指定设置值
     */
    public static function getOption(string $key, $default = null)
    {
        $option = static::find($key);
        return $option ? $option->value : $default;
    }

    /**
     * 设置指定设置值
     */
    public static function setOption(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * 批量获取设置
     */
    public static function getOptions(array $keys = []): array
    {
        $query = static::query();
        if (!empty($keys)) {
            $query->whereIn('key', $keys);
        }
        return $query->pluck('value', 'key')->toArray();
    }
}
