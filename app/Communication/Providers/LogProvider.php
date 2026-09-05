<?php

declare(strict_types=1);

namespace App\Communication\Providers;

use App\Communication\Contracts\CommunicationProvider;
use App\Communication\OutboundMessage;
use App\Communication\ProviderResult;
use App\Enums\CommunicationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default provider for every channel (M16). Records the message to the log
 * and "accepts" it — NO external API call, NO real send. It never confirms
 * delivery, so a LogProvider message ends its life at SENT (correct: we do not
 * claim delivery we cannot observe).
 *
 * Sensitive content is not logged verbatim — only the address, a length and a
 * body hash.
 */
final class LogProvider implements CommunicationProvider
{
    public function __construct(private readonly CommunicationChannel $channel) {}

    public function channel(): CommunicationChannel
    {
        return $this->channel;
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        Log::channel(config('communication.log_channel', null) ?: config('logging.default'))
            ->info('communication.log_provider.send', [
                'channel' => $message->channel->value,
                'category' => $message->category->value,
                'to' => $this->maskAddress($message->to),
                'subject' => $message->subject,
                'body_length' => mb_strlen($message->body),
                'body_sha1' => sha1($message->body),
                'reference' => $message->reference,
            ]);

        return ProviderResult::accepted('log-'.Str::uuid()->toString(), confirmsDelivery: false);
    }

    private function maskAddress(string $to): string
    {
        if (str_contains($to, '@')) {
            [$local, $domain] = explode('@', $to, 2);

            return Str::limit($local, 2, '').'…@'.$domain;
        }

        return Str::mask($to, '•', 2, max(0, strlen($to) - 5));
    }
}
