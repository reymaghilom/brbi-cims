<?php

namespace App\Http\Requests\Integrations;

use App\Services\Integrations\N8n\ReminderScheduleOutbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClaimN8nReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['bail', 'required', 'uuid'],
            'subject_type' => ['bail', 'required', 'string', Rule::in([
                ReminderScheduleOutbox::SUBJECT_ACTIVITY,
                ReminderScheduleOutbox::SUBJECT_BANK_TARGET,
                ReminderScheduleOutbox::SUBJECT_ASSET_TARGET,
            ])],
            'subject_id' => ['bail', 'required', 'integer', 'min:1'],
            'ci_activity_id' => ['bail', 'required', 'integer', 'min:1'],
            'schedule_version' => ['bail', 'required', 'integer', 'min:1'],
            'occurrence_at' => ['bail', 'required', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('payload', 'Unexpected fields are not allowed.');
            }
        }];
    }

    /** @return array{event_id: string, subject_type: string, subject_id: int, ci_activity_id: int, schedule_version: int, occurrence_at: string} */
    public function claimData(): array
    {
        $validated = $this->validated();

        return [
            'event_id' => $validated['event_id'],
            'subject_type' => $validated['subject_type'],
            'subject_id' => (int) $validated['subject_id'],
            'ci_activity_id' => (int) $validated['ci_activity_id'],
            'schedule_version' => (int) $validated['schedule_version'],
            'occurrence_at' => $validated['occurrence_at'],
        ];
    }
}
