<?php

declare(strict_types=1);

namespace App\Communication\Providers;

use App\Communication\Contracts\CommunicationProvider;
use App\Communication\OutboundMessage;
use App\Communication\ProviderResult;
use App\Enums\CommunicationChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Email via Laravel's already-configured Mail stack (M16) — no new mail system.
 * With `MAIL_MAILER=log` (the default) this writes to the log; a real transport
 * is opt-in through the existing `config/mail.php`. Laravel's SMTP transport
 * confirms submission only, so this never reports DELIVERED.
 */
final class EmailProvider implements CommunicationProvider
{
    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::Email;
    }

    public function name(): string
    {
        return 'mail:'.config('mail.default');
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        $from = config('communication.channels.email.from');

        try {
            Mail::html($message->body, function ($mail) use ($message, $from): void {
                $mail->to($message->to)->subject($message->subject ?? config('app.name'));

                if (is_array($from) && ! empty($from['address'])) {
                    $mail->from($from['address'], $from['name'] ?? config('app.name'));
                }
            });
        } catch (TransportExceptionInterface $e) {
            return ProviderResult::temporaryFailure($e->getMessage());
        } catch (\Throwable $e) {
            return ProviderResult::permanentFailure($e->getMessage());
        }

        return ProviderResult::accepted('mail-'.Str::uuid()->toString(), confirmsDelivery: false);
    }
}
