<?php

namespace app\controller\admin;

use support\Request;
use app\model\Option;
use app\model\BlockedWord;

class OptionController
{
    /**
     * 获取所有设置
     */
    public function index(Request $request)
    {
        $group = $request->input('group', '');

        $groupKeys = [
            'site' => ['site_name', 'site_logo', 'site_footer', 'notice', 'system_announcement'],
            'log' => ['log_detail_enabled'],
            'blocked_words' => ['blocked_word_upstream_enabled', 'blocked_word_input_enabled'],
            'payment' => ['pay_address', 'pay_id', 'pay_key', 'pay_channels'],
            'smtp' => ['smtp_server', 'smtp_port', 'smtp_account', 'smtp_token', 'smtp_from', 'smtp_encryption', 'email_verification_enabled'],
            'rate_limit' => ['rate_limit_global', 'rate_limit_per_user'],
            'performance' => ['cache_ttl', 'worker_num', 'auto_disable_channel_enabled', 'channel_disable_threshold'],
            'quota' => ['new_user_initial_balance', 'pre_deduct_amount', 'invite_reward_amount'],
            'checkin' => ['checkin_enabled', 'checkin_min_amount', 'checkin_max_amount'],
            'captcha' => ['captcha_length', 'captcha_chars'],
            'recharge' => ['recharge_url'],
            'register' => ['register_enabled', 'register_email_verify'],
            'terms' => ['privacy_policy', 'terms_of_service'],
        ];

        if ($group && isset($groupKeys[$group])) {
            $options = Option::getOptions($groupKeys[$group]);
        } else {
            $options = Option::getOptions();
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $options]);
    }

    /**
     * 批量更新设置
     */
    public function update(Request $request)
    {
        $options = $request->post('options', []);
        if (empty($options) || !is_array($options)) {
            return json(['code' => 400, 'msg' => '设置数据不能为空']);
        }

        foreach ($options as $key => $value) {
            Option::setOption($key, (string)$value);
        }

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 屏蔽词列表
     */
    public function blockedWords(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $type = $request->input('type');
        $keyword = $request->input('keyword', '');

        $query = BlockedWord::query()->orderBy('id', 'desc');

        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
        }
        if ($keyword !== '') {
            $query->where('word', 'like', "%{$keyword}%");
        }

        $paginator = $query->paginate($perPage);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]]);
    }

    /**
     * 添加屏蔽词
     */
    public function addBlockedWord(Request $request)
    {
        $word = $request->post('word', '');
        if (!$word) {
            return json(['code' => 400, 'msg' => '屏蔽词不能为空']);
        }

        $blockedWord = BlockedWord::create([
            'word' => $word,
            'type' => (int)$request->post('type', BlockedWord::TYPE_BOTH),
            'action' => (int)$request->post('action', BlockedWord::ACTION_REPLACE),
            'replacement' => $request->post('replacement', '***'),
            'status' => (int)$request->post('status', 1),
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $blockedWord]);
    }

    /**
     * 更新屏蔽词
     */
    public function updateBlockedWord(Request $request, $id)
    {
        $blockedWord = BlockedWord::find($id);
        if (!$blockedWord) {
            return json(['code' => 404, 'msg' => '屏蔽词不存在']);
        }

        $fields = ['word', 'type', 'action', 'replacement', 'status'];
        foreach ($fields as $field) {
            $value = $request->post($field);
            if ($value !== null) {
                $blockedWord->$field = $value;
            }
        }
        $blockedWord->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $blockedWord]);
    }

    /**
     * 删除屏蔽词
     */
    public function deleteBlockedWord(Request $request, $id)
    {
        $blockedWord = BlockedWord::find($id);
        if (!$blockedWord) {
            return json(['code' => 404, 'msg' => '屏蔽词不存在']);
        }
        $blockedWord->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
