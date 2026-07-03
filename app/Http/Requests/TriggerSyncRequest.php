<?php

namespace App\Http\Requests;

use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/sync/trigger` gövde validasyonu. `provider` alanı
 * `ProviderType` enum'unun değerlerinden biri olmalı (`dummyjson` veya
 * `fakestore`); geçersiz/eksik değer 422 + standart hata zarfıyla döner
 * (bkz. app/Exceptions/Handler.php).
 */
class TriggerSyncRequest extends FormRequest
{
    /**
     * Bu endpoint için ayrı bir yetkilendirme kuralı yok, herkes tetikleyebilir.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(ProviderType::class)],
        ];
    }
}
