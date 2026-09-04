<?php

namespace App\Services\Media;

use App\Models\AuditLog;
use App\Models\DocumentationTelegramDelivery;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DocumentationTelegramSender
{
    public function __construct(
        private readonly DocumentationCaptionBuilder $captionBuilder,
        private readonly CiTeamDocumentStorage $documents,
    ) {}

    public function configured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    public function send(ResidenceBusinessDocumentation $documentation, User $actor, ?string $messageBody = null): DocumentationTelegramDelivery
    {
        return $this->sendDocumentation($documentation, $actor, $messageBody);
    }

    /**
     * @param  Collection<int, ResidenceBusinessDocumentation>  $documentations
     * @return Collection<int, DocumentationTelegramDelivery>
     */
    public function sendAllBusinesses(Collection $documentations, User $actor): Collection
    {
        if ($documentations->isEmpty()) {
            throw new DocumentationTelegramSendException('There are no saved Business Documentation records to send.');
        }

        $ordered = $documentations->sortBy('id')->values();
        $batchFingerprint = hash('sha256', $ordered->map(fn (ResidenceBusinessDocumentation $documentation): array => [
            $documentation->id,
            $documentation->updated_at?->toJSON(),
        ])->toJson());
        $deliveries = collect();

        foreach ($ordered as $index => $documentation) {
            $savedCaption = $documentation->latestTelegramDelivery?->message_body;
            $deliveries->push($this->sendDocumentation(
                $documentation,
                $actor,
                filled($savedCaption) ? $savedCaption : null,
                $index === 0 ? 'Business Pictures and Videos' : null,
                'business-batch:'.$batchFingerprint,
            ));
        }

        return $deliveries;
    }

    private function sendDocumentation(ResidenceBusinessDocumentation $documentation, User $actor, ?string $messageBody = null, ?string $heading = null, ?string $deliveryScope = null): DocumentationTelegramDelivery
    {
        if (! $this->configured()) {
            throw new DocumentationTelegramSendException('Telegram is not connected. Add the required Telegram configuration first.');
        }

        $documentation->load(['clientFolder', 'coMaker', 'mapScreenshot']);
        $pictures = $documentation->pictures()->orderBy('id')->get();
        $videos = $documentation->videos()->orderBy('id')->get();
        $this->assertEligible($documentation, $pictures->count(), $deliveryScope !== null);

        $messageBody = trim($messageBody ?? $this->captionBuilder->build($documentation));
        if ($messageBody === '') {
            throw new DocumentationTelegramSendException('Telegram message cannot be empty.');
        }
        if (mb_strlen($messageBody) > $this->captionBuilder->maxMessageBodyLength($actor)) {
            throw new DocumentationTelegramSendException('Telegram message is too long. Please shorten it before sending.');
        }

        $caption = $this->captionBuilder->buildForSender($documentation, $actor, $messageBody);
        $operations = $this->operations($documentation, $caption, $pictures->all(), $videos->all(), $heading);
        $fingerprint = hash('sha256', json_encode(array_map(fn (array $operation): array => [
            $operation['key'],
            $operation['text'],
            $operation['media']?->checksum,
            $operation['media']?->updated_at?->toJSON(),
        ], $operations), JSON_THROW_ON_ERROR)."\n".$caption."\n".$deliveryScope);
        $chatId = (string) config('services.telegram.chat_id');

        $delivery = $this->reserveDelivery($documentation, $actor, $chatId, $fingerprint, $messageBody, $deliveryScope);
        if ($delivery->status === DocumentationTelegramDelivery::STATUS_SENT) {
            return $delivery;
        }
        $messageIds = $delivery->message_ids ?? [];

        try {
            foreach ($operations as $operation) {
                if ($operation['media']) {
                    $this->assertMediaAvailable($operation['media']);
                }
            }

            foreach ($operations as $operation) {
                if (array_key_exists($operation['key'], $messageIds)) {
                    continue;
                }

                // Keep the exact in-flight operation on the delivery. If Telegram rejects it,
                // operators can identify the failed step without storing a secret request URL.
                $delivery->update(['active_key' => $this->activeKey($documentation, $operation['key'], $deliveryScope)]);
                $messageIds[$operation['key']] = $operation['type'] === 'text'
                    ? $this->sendText($chatId, $operation['text'], $operation['key'])
                    : $this->sendMedia($chatId, $operation['type'], $operation['media'], $operation['key']);

                // Persist every accepted message immediately. A retry of this same payload resumes
                // after the last accepted item instead of duplicating the partial Telegram send.
                $delivery->update(['message_ids' => $messageIds]);
            }

            DB::transaction(function () use ($delivery, $documentation, $actor, $pictures, $videos, $deliveryScope): void {
                $delivery->update([
                    'status' => DocumentationTelegramDelivery::STATUS_SENT,
                    'active_key' => null,
                    'error_summary' => null,
                    'completed_at' => now(),
                ]);

                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $documentation->client_folder_id,
                    'action' => 'residence_business_documentation.telegram_sent',
                    'module' => 'media',
                    'description' => 'Sent '.ucfirst($documentation->category).' Documentation to Telegram.',
                    'metadata' => [
                        'residence_business_documentation_id' => $documentation->id,
                        'category' => $documentation->category,
                        'co_maker_id' => $documentation->co_maker_id,
                        'business_name' => $documentation->business_name,
                        'delivery_id' => $delivery->id,
                        'pictures_count' => $pictures->count(),
                        'videos_count' => $videos->count(),
                        'map_screenshot_included' => $documentation->mapScreenshot !== null,
                        'delivery_scope' => $deliveryScope,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            });

            return $delivery->refresh();
        } catch (Throwable $exception) {
            $message = $exception instanceof DocumentationTelegramSendException
                ? $exception->getMessage()
                : 'Telegram could not complete the send. Please try again.';

            $delivery->update([
                'status' => DocumentationTelegramDelivery::STATUS_FAILED,
                'error_summary' => $message,
                'completed_at' => now(),
            ]);

            // Do not chain the HTTP exception: transport exception text may contain the Bot API
            // URL, whose path contains the token. Only the safe operational message leaves here.
            throw new DocumentationTelegramSendException($message);
        }
    }

    private function reserveDelivery(ResidenceBusinessDocumentation $documentation, User $actor, string $chatId, string $fingerprint, string $messageBody, ?string $deliveryScope = null): DocumentationTelegramDelivery
    {
        return DB::transaction(function () use ($documentation, $actor, $chatId, $fingerprint, $messageBody, $deliveryScope): DocumentationTelegramDelivery {
            ResidenceBusinessDocumentation::query()->whereKey($documentation->id)->lockForUpdate()->firstOrFail();
            $deliveries = DocumentationTelegramDelivery::query()
                ->where('residence_business_documentation_id', $documentation->id)
                ->latest('id');

            if ($deliveryScope !== null) {
                $matching = (clone $deliveries)->where('payload_fingerprint', $fingerprint);
                $sent = (clone $matching)->where('status', DocumentationTelegramDelivery::STATUS_SENT)->first();
                if ($sent) {
                    return $sent;
                }

                if ((clone $matching)->where('status', DocumentationTelegramDelivery::STATUS_SENDING)->exists()) {
                    throw new DocumentationTelegramSendException('This business is already being sent as part of this Telegram package.');
                }
            } elseif ((clone $deliveries)->where('status', DocumentationTelegramDelivery::STATUS_SENT)->exists()) {
                throw new DocumentationTelegramSendException('This documentation was already successfully sent to Telegram.');
            }

            if ($deliveryScope === null && (clone $deliveries)->where('status', DocumentationTelegramDelivery::STATUS_SENDING)->exists()) {
                throw new DocumentationTelegramSendException('This documentation is already being sent to Telegram.');
            }

            $failed = (clone $deliveries)->where('status', DocumentationTelegramDelivery::STATUS_FAILED)
                ->when($deliveryScope !== null, fn ($query) => $query->where('payload_fingerprint', $fingerprint))
                ->first();
            if ($failed && $failed->payload_fingerprint === $fingerprint) {
                $failed->update([
                    'sent_by' => $actor->id,
                    'message_body' => $messageBody,
                    'status' => DocumentationTelegramDelivery::STATUS_SENDING,
                    'active_key' => $this->activeKey($documentation, 'reservation', $deliveryScope),
                    'error_summary' => null,
                    'completed_at' => null,
                ]);

                return $failed->refresh();
            }

            if ($failed && filled($failed->message_ids)) {
                throw new DocumentationTelegramSendException('The saved documentation changed after a partial Telegram send. Review it before trying again.');
            }

            // Nothing reached Telegram, so this remains the same retryable delivery. Reassign it
            // to the authenticated retrying user and replace the fingerprint/caption together;
            // sender text and sent_by therefore cannot disagree.
            if ($failed) {
                $failed->update([
                    'sent_by' => $actor->id,
                    'chat_id_snapshot' => $chatId,
                    'status' => DocumentationTelegramDelivery::STATUS_SENDING,
                    'payload_fingerprint' => $fingerprint,
                    'message_body' => $messageBody,
                    'active_key' => $this->activeKey($documentation, 'reservation', $deliveryScope),
                    'error_summary' => null,
                    'completed_at' => null,
                    'started_at' => now(),
                ]);

                return $failed->refresh();
            }

            return DocumentationTelegramDelivery::create([
                'residence_business_documentation_id' => $documentation->id,
                'client_folder_id' => $documentation->client_folder_id,
                'co_maker_id' => $documentation->co_maker_id,
                'sent_by' => $actor->id,
                'chat_id_snapshot' => $chatId,
                'status' => DocumentationTelegramDelivery::STATUS_SENDING,
                'payload_fingerprint' => $fingerprint,
                'message_body' => $messageBody,
                'active_key' => $this->activeKey($documentation, 'reservation', $deliveryScope),
                'message_ids' => [],
                'started_at' => now(),
            ]);
        });
    }

    private function assertEligible(ResidenceBusinessDocumentation $documentation, int $picturesCount, bool $businessBatch = false): void
    {
        if (! in_array($documentation->category, [ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, ResidenceBusinessDocumentation::CATEGORY_BUSINESS], true)) {
            throw new DocumentationTelegramSendException('This documentation type cannot be sent to Telegram.');
        }

        if (! $businessBatch && blank($documentation->location)) {
            throw new DocumentationTelegramSendException('Add a location before sending to Telegram.');
        }

        if (! $businessBatch && $picturesCount < 1) {
            $subject = $documentation->isResidence() ? 'Residence' : 'Business';
            throw new DocumentationTelegramSendException("Add at least one {$subject} Picture before sending to Telegram.");
        }

        if (! $businessBatch && ! $documentation->isResidence() && $documentation->mapScreenshot === null) {
            throw new DocumentationTelegramSendException('Add a Google Map Screenshot before sending this Business Documentation to Telegram.');
        }
    }

    /** @param  list<MediaReference>  $pictures
     * @param  list<MediaReference>  $videos
     * @return list<array{key: string, type: string, media: ?MediaReference, text: ?string}>
     */
    private function operations(ResidenceBusinessDocumentation $documentation, string $caption, array $pictures, array $videos, ?string $heading = null): array
    {
        $operations = [];
        if (filled($heading)) {
            $operations[] = ['key' => 'heading', 'type' => 'text', 'media' => null, 'text' => $heading];
        }
        $operations[] = ['key' => 'caption', 'type' => 'text', 'media' => null, 'text' => $caption];
        if ($documentation->mapScreenshot) {
            $operations[] = ['key' => 'map:'.$documentation->mapScreenshot->id, 'type' => 'photo', 'media' => $documentation->mapScreenshot, 'text' => null];
        }
        foreach ($pictures as $picture) {
            $operations[] = ['key' => 'picture:'.$picture->id, 'type' => 'photo', 'media' => $picture, 'text' => null];
        }
        foreach ($videos as $video) {
            $operations[] = ['key' => 'video:'.$video->id, 'type' => 'video', 'media' => $video, 'text' => null];
        }

        return $operations;
    }

    private function sendText(string $chatId, string $caption, string $step): int
    {
        $response = $this->request()->post($this->endpoint('sendMessage'), [
            'chat_id' => $chatId,
            'text' => $caption,
        ]);

        return $this->acceptedMessageId($response->successful(), $response->json(), $step);
    }

    private function sendMedia(string $chatId, string $type, MediaReference $media, string $step): int
    {
        $this->assertMediaAvailable($media);
        $disk = $this->documents->diskForMedia($media, $media->temporary_local_path);
        $stream = $disk->readStream($media->temporary_local_path);
        if (! is_resource($stream)) {
            throw new DocumentationTelegramSendException('One of the saved media files could not be found. Please verify the documentation before sending again.');
        }

        try {
            $field = $type === 'video' ? 'video' : 'photo';
            $response = $this->request()
                ->attach($field, $stream, $media->file_name, ['Content-Type' => $media->mime_type])
                ->post($this->endpoint($type === 'video' ? 'sendVideo' : 'sendPhoto'), ['chat_id' => $chatId]);

            return $this->acceptedMessageId($response->successful(), $response->json(), $step);
        } finally {
            fclose($stream);
        }
    }

    private function assertMediaAvailable(MediaReference $media): void
    {
        if (! in_array($media->storage_provider, [MediaReference::STORAGE_PROVIDER_LOCAL, MediaReference::STORAGE_PROVIDER_CI_TEAM], true)
            || blank($media->temporary_local_path)
            || ! $this->documents->diskForMedia($media, $media->temporary_local_path)->exists($media->temporary_local_path)) {
            throw new DocumentationTelegramSendException('One of the saved media files could not be found. Please verify the documentation before sending again.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(45);
    }

    private function activeKey(ResidenceBusinessDocumentation $documentation, string $step, ?string $deliveryScope): string
    {
        if ($deliveryScope === null) {
            return $step === 'reservation' ? 'documentation:'.$documentation->id : $step;
        }

        return 'business-batch:'.substr(hash('sha256', $deliveryScope), 0, 16).':'.$documentation->id.':'.$step;
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }

    private function acceptedMessageId(bool $successful, mixed $payload, string $step): int
    {
        $messageId = is_array($payload) ? data_get($payload, 'result.message_id') : null;
        if (! $successful || data_get($payload, 'ok') !== true || ! is_numeric($messageId)) {
            throw new DocumentationTelegramSendException($this->safeRejectionMessage($payload, $step));
        }

        return (int) $messageId;
    }

    private function safeRejectionMessage(mixed $payload, string $step): string
    {
        $errorCode = is_array($payload) && is_numeric(data_get($payload, 'error_code'))
            ? (int) data_get($payload, 'error_code')
            : null;
        $description = is_array($payload) && is_string(data_get($payload, 'description'))
            ? Str::of(data_get($payload, 'description'))
                ->replaceMatches('~https?://\S+~i', '[redacted URL]')
                ->replaceMatches('~bot\d+:[A-Za-z0-9_-]+~i', '[redacted token]')
                ->squish()
                ->limit(240, '')
                ->toString()
            : null;
        $stepLabel = match (true) {
            $step === 'caption' => 'Caption',
            str_starts_with($step, 'map:') => 'Google Map Screenshot',
            str_starts_with($step, 'picture:') => 'Residence/Business Picture',
            str_starts_with($step, 'video:') => 'Video',
            default => 'Documentation item',
        };

        if ($errorCode !== null && filled($description)) {
            $guidance = str_contains(strtolower($description), 'chat not found')
                ? ' Verify that the configured group is correct and the BRBI CIMS Bot is still a member.'
                : '';

            return "Telegram rejected {$stepLabel} ({$errorCode}: {$description}).{$guidance}";
        }

        return "Telegram rejected {$stepLabel}. Verify the bot and chat configuration, then try again.";
    }
}
