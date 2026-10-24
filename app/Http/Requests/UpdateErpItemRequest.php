<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateErpItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'price' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'stocks' => ['sometimes', 'array', 'min:1'],
            'stocks.*.location' => ['required_with:stocks', 'string', 'max:64'],
            'stocks.*.on_hand' => ['required_with:stocks', 'integer', 'min:0'],
            'stocks.*.safety_stock' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if (! $this->hasAny(['price', 'compare_at_price', 'stocks'])) {
                $v->errors()->add('payload', 'Au moins un champ parmi price, compare_at_price, stocks est requis.');
            }
        });
    }
}
