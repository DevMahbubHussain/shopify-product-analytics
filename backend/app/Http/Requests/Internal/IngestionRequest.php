<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

abstract class IngestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function shopDomain(): string
    {
        return (string) $this->attributes->get('shop_domain');
    }
}
