<?php

namespace App\Http\Requests\Admin\AIProvider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAIProviderConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'configuration' => ['required', 'array:default_provider,providers,language_rules,models'],
            'configuration.default_provider' => ['required', 'string', 'max:40'],
            'configuration.providers' => ['required', 'array', 'min:1', 'max:10'],
            'configuration.providers.*' => ['required', 'array:enabled,model,languages'],
            'configuration.providers.*.enabled' => ['required', 'boolean'],
            'configuration.providers.*.model' => ['required', 'string', 'max:120'],
            'configuration.providers.*.languages' => ['present', 'array', 'max:250'],
            'configuration.providers.*.languages.*' => ['required', 'string', 'regex:/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/'],
            'configuration.language_rules' => ['present', 'array', 'max:250'],
            'configuration.language_rules.*' => Rule::forEach(fn (mixed $value): array => is_array($value) ? ['required', 'array:provider,model'] : ['required', 'string', 'max:40']),
            'configuration.language_rules.*.provider' => ['sometimes', 'required', 'string', 'max:40'],
            'configuration.language_rules.*.model' => ['sometimes', 'required', 'string', 'max:120'],
            'configuration.models' => ['sometimes', 'array', 'max:10'],
            'configuration.models.*' => ['present', 'array', 'list', 'min:1', 'max:30'],
            'configuration.models.*.*' => ['required', 'array:id,label,enabled,languages,capabilities,pricing'],
            'configuration.models.*.*.id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'configuration.models.*.*.label' => ['required', 'string', 'max:120', 'regex:/\S/'],
            'configuration.models.*.*.enabled' => ['required', 'boolean'],
            'configuration.models.*.*.languages' => ['present', 'array', 'list', 'max:250'],
            'configuration.models.*.*.languages.*' => ['required', 'string', 'regex:/^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/'],
            'configuration.models.*.*.capabilities' => ['required', 'array:speakers,timestamps'],
            'configuration.models.*.*.capabilities.speakers' => ['required', 'boolean'],
            'configuration.models.*.*.capabilities.timestamps' => ['required', 'boolean'],
            'configuration.models.*.*.pricing' => ['required', 'array:unit,credits,provider_cost,provider_currency'],
            'configuration.models.*.*.pricing.unit' => ['required', 'string', 'in:minute,character,1000_characters'],
            'configuration.models.*.*.pricing.credits' => ['present', 'nullable', 'string', 'max:20'],
            'configuration.models.*.*.pricing.provider_cost' => ['present', 'nullable', 'string', 'max:20'],
            'configuration.models.*.*.pricing.provider_currency' => ['required', 'string', 'in:USD,NGN']];
    }
}
