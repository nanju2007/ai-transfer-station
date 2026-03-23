<?php

namespace app\controller\admin;

use support\Request;
use app\model\ModelPricing;
use app\model\Model_;

class ModelPricingController
{
    /**
     * 计费列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $modelId = $request->input('model_id');

        $query = ModelPricing::with('model')->orderBy('id', 'desc');

        if ($modelId !== null && $modelId !== '') {
            $query->where('model_id', (int)$modelId);
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
     * 创建计费规则
     */
    public function store(Request $request)
    {
        $modelId = (int)$request->post('model_id', 0);
        if (!$modelId) {
            return json(['code' => 400, 'msg' => '模型ID不能为空']);
        }

        if (!Model_::find($modelId)) {
            return json(['code' => 404, 'msg' => '模型不存在']);
        }

        if (ModelPricing::where('model_id', $modelId)->exists()) {
            return json(['code' => 400, 'msg' => '该模型已有计费规则']);
        }

        $billingType = (int)$request->post('billing_type', 1);

        $pricing = ModelPricing::create([
            'model_id' => $modelId,
            'billing_type' => $billingType,
            'input_price' => $request->post('input_price', 0),
            'output_price' => $request->post('output_price', 0),
            'per_request_price' => $request->post('per_request_price', 0),
            'min_charge' => $request->post('min_charge', 0),
            'cache_input_ratio' => $request->post('cache_input_ratio', 1.0),
            'cache_enabled' => (int)$request->post('cache_enabled', 0),
            'cache_creation_price' => $request->post('cache_creation_price', 0),
            'cache_read_price' => $request->post('cache_read_price', 0),
            'currency' => $request->post('currency', 'CNY'),
            'status' => (int)$request->post('status', 1),
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $pricing]);
    }

    /**
     * 更新计费规则
     */
    public function update(Request $request, $id)
    {
        $pricing = ModelPricing::find($id);
        if (!$pricing) {
            return json(['code' => 404, 'msg' => '计费规则不存在']);
        }

        $fields = ['billing_type', 'input_price', 'output_price', 'per_request_price',
            'min_charge', 'cache_input_ratio', 'cache_enabled', 'cache_creation_price',
            'cache_read_price', 'currency', 'status'];

        foreach ($fields as $field) {
            $value = $request->post($field);
            if ($value !== null) {
                $pricing->$field = $value;
            }
        }
        $pricing->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $pricing]);
    }

    /**
     * 删除计费规则
     */
    public function destroy(Request $request, $id)
    {
        $pricing = ModelPricing::find($id);
        if (!$pricing) {
            return json(['code' => 404, 'msg' => '计费规则不存在']);
        }
        $pricing->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
