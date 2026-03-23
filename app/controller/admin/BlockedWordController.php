<?php

namespace app\controller\admin;

use support\Request;
use app\model\BlockedWord;

class BlockedWordController
{
    /**
     * 屏蔽词列表
     */
    public function index(Request $request)
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
    public function store(Request $request)
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
    public function update(Request $request, $id)
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
    public function destroy(Request $request, $id)
    {
        $blockedWord = BlockedWord::find($id);
        if (!$blockedWord) {
            return json(['code' => 404, 'msg' => '屏蔽词不存在']);
        }
        $blockedWord->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 批量导入屏蔽词
     */
    public function batchStore(Request $request)
    {
        $words = $request->post('words', []);
        if (empty($words) || !is_array($words)) {
            return json(['code' => 400, 'msg' => '屏蔽词列表不能为空']);
        }

        $type = (int)$request->post('type', BlockedWord::TYPE_BOTH);
        $action = (int)$request->post('action', BlockedWord::ACTION_REPLACE);
        $replacement = $request->post('replacement', '***');

        $added = 0;
        foreach ($words as $word) {
            $word = trim($word);
            if (!$word) continue;

            if (!BlockedWord::where('word', $word)->exists()) {
                BlockedWord::create([
                    'word' => $word,
                    'type' => $type,
                    'action' => $action,
                    'replacement' => $replacement,
                    'status' => 1,
                ]);
                $added++;
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['added' => $added]]);
    }
}
