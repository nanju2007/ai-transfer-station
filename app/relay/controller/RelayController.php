<?php

namespace app\relay\controller;

use app\service\RelayService;
use app\model\Model_;
use support\Request;
use support\Response;

class RelayController
{
    /**
     * POST /v1/chat/completions
     */
    public function chatCompletions(Request $request): Response
    {
        return RelayService::handleChatCompletions($request);
    }

    /**
     * POST /v1/completions (文本补全，走同一逻辑)
     */
    public function completions(Request $request): Response
    {
        return RelayService::handleChatCompletions($request);
    }

    /**
     * POST /v1/messages
     */
    public function messages(Request $request): Response
    {
        return RelayService::handleChatCompletions($request);
    }

    /**
     * POST /v1/embeddings
     */
    public function embeddings(Request $request): Response
    {
        return RelayService::errorResponse('Embeddings接口暂未实现', 'not_implemented', 501);
    }

    /**
     * POST /v1/images/generations
     */
    public function imageGenerations(Request $request): Response
    {
        return RelayService::errorResponse('图像生成接口暂未实现', 'not_implemented', 501);
    }

    /**
     * POST /v1/audio/transcriptions
     */
    public function audioTranscriptions(Request $request): Response
    {
        return RelayService::errorResponse('语音转文字接口暂未实现', 'not_implemented', 501);
    }

    /**
     * POST /v1/audio/speech
     */
    public function audioSpeech(Request $request): Response
    {
        return RelayService::errorResponse('文字转语音接口暂未实现', 'not_implemented', 501);
    }

    /**
     * POST /v1/moderations
     */
    public function moderations(Request $request): Response
    {
        return RelayService::errorResponse('内容审核接口暂未实现', 'not_implemented', 501);
    }

    /**
     * GET /v1/models - 返回可用模型列表（OpenAI格式）
     */
    public function models(Request $request): Response
    {
        $models = Model_::where('status', 1)
            ->orderBy('sort_order', 'desc')
            ->orderBy('model_name', 'asc')
            ->get();

        $data = [];
        foreach ($models as $model) {
            $data[] = [
                'id' => $model->model_name,
                'object' => 'model',
                'created' => strtotime($model->created_at),
                'owned_by' => $model->vendor ?: 'system',
                'permission' => [],
                'root' => $model->model_name,
                'parent' => null,
            ];
        }

        return json([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    /**
     * GET /v1/models/{model} - 返回单个模型信息
     */
    public function modelDetail(Request $request, string $model): Response
    {
        $modelRecord = Model_::where('model_name', $model)->where('status', 1)->first();

        if (!$modelRecord) {
            return RelayService::errorResponse("模型 {$model} 不存在", 'model_not_found', 404);
        }

        return json([
            'id' => $modelRecord->model_name,
            'object' => 'model',
            'created' => strtotime($modelRecord->created_at),
            'owned_by' => $modelRecord->vendor ?: 'system',
            'permission' => [],
            'root' => $modelRecord->model_name,
            'parent' => null,
        ]);
    }
}
