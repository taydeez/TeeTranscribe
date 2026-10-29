<?php

namespace App\Http\Requests\Admin\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBillingConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
            'configuration' => ['required', 'array:packages,free_credits,fx,quote_minutes,checkout_minutes,processing_timeout_hours,minimum_checkout,enabled_currencies,gateways,activities,concurrency,task_deadlines'],
            'configuration.task_deadlines' => ['sometimes', 'required', 'array:transcription,translation,video_dubbing,audio_dubbing,subtitles,cleanup,summary'],
            'configuration.task_deadlines.*' => ['required', 'array', 'max:30'],
            'configuration.task_deadlines.*.default' => ['required_with:configuration.task_deadlines', 'array'],
            'configuration.task_deadlines.*.*' => ['required', 'array:base_minutes,duration_multiplier,max_minutes'],
            'configuration.task_deadlines.*.*.base_minutes' => ['required', 'integer', 'between:1,1440'],
            'configuration.task_deadlines.*.*.duration_multiplier' => ['required', 'numeric', 'between:0,20'],
            'configuration.task_deadlines.*.*.max_minutes' => ['required', 'integer', 'between:1,10080', 'gte:configuration.task_deadlines.*.*.base_minutes'],
            'configuration.concurrency' => ['sometimes', 'required', 'array:overall,transcription,translation,dubbing,transcript_tools'],
            'configuration.packages' => ['present', 'array', 'list', 'max:50'],
            'configuration.packages.*' => ['required', 'array:code,name,credits,price_ngn,enabled'],
            'configuration.packages.*.code' => ['required', 'string', 'max:60', 'distinct', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'configuration.packages.*.name' => ['required', 'string', 'max:120', 'regex:/\S/'],
            'configuration.packages.*.credits' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'configuration.packages.*.price_ngn' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'configuration.packages.*.enabled' => ['required', 'boolean'],
            'configuration.free_credits' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'configuration.fx' => ['required', 'array:ngn_per_usd,updated_at,max_age_hours'],
            'configuration.fx.ngn_per_usd' => ['present', 'nullable', 'string', 'regex:/^\d{1,7}(?:\.\d{1,6})?$/D'],
            'configuration.fx.updated_at' => ['present', 'nullable', 'required_with:configuration.fx.ngn_per_usd', 'date', 'before_or_equal:now'],
            'configuration.fx.max_age_hours' => ['required', 'integer', 'between:1,720'],
            'configuration.quote_minutes' => ['required', 'integer', 'between:1,1440'],
            'configuration.checkout_minutes' => ['required', 'integer', 'between:1,1440'],
            'configuration.processing_timeout_hours' => ['required', 'integer', 'between:26,168'],
            'configuration.minimum_checkout' => ['required', 'array:NGN,USD'],
            'configuration.minimum_checkout.NGN' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'configuration.minimum_checkout.USD' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
            'configuration.enabled_currencies' => ['present', 'array', 'list', 'max:2'],
            'configuration.enabled_currencies.*' => ['required', 'in:NGN,USD', 'distinct'],
            'configuration.gateways' => ['required', 'array:paystack,flutterwave'],
            'configuration.activities' => ['required', 'array:transcription,translation,video_dubbing,audio_dubbing,subtitles,cleanup,summary']];
        foreach (['overall', 'transcription', 'translation', 'dubbing', 'transcript_tools'] as $limit) {
            $rules['configuration.concurrency.'.$limit] = ['required_with:configuration.concurrency', 'integer', 'between:1,100'];
        }
        foreach (['paystack', 'flutterwave'] as $gateway) {
            $key = 'configuration.gateways.'.$gateway;
            $rules[$key] = ['required', 'array:enabled,currencies'];
            $rules[$key.'.enabled'] = ['required', 'boolean'];
            $rules[$key.'.currencies'] = ['present', 'array', 'list', 'max:2'];
            $rules[$key.'.currencies.*'] = ['required', 'in:NGN,USD', 'distinct'];
        }
        foreach (['transcription', 'translation', 'video_dubbing', 'audio_dubbing', 'subtitles', 'cleanup', 'summary'] as $activity) {
            $key = 'configuration.activities.'.$activity;
            $rules[$key] = ['required', 'array:mode,credits'];
            $rules[$key.'.mode'] = ['required', 'in:model,activity'];
            $rules[$key.'.credits'] = ['present', 'nullable', 'required_if:'.$key.'.mode,activity', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'];
        }

        return $rules;
    }
}
