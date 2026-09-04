<?php

namespace App\Http\Requests\ClientFolders;

use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Media\DocumentationCaptionBuilder;
use Illuminate\Foundation\Http\FormRequest;

class SendDocumentationToTelegramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('documentation'));
    }

    public function rules(): array
    {
        $maxBodyLength = app(DocumentationCaptionBuilder::class)->maxMessageBodyLength($this->user());

        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'telegram_message_body' => ['sometimes', 'required', 'string', 'max:'.$maxBodyLength],
        ];
    }

    public function messages(): array
    {
        return [
            'telegram_message_body.required' => 'Telegram message cannot be empty.',
            'telegram_message_body.max' => 'Telegram message is too long. Please shorten it before sending.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('telegram_message_body')) {
            $this->merge(['telegram_message_body' => trim((string) $this->input('telegram_message_body'))]);
        }
    }
}
