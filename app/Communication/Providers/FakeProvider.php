<?php

declare(strict_types=1);

namespace App\Communication\Providers;

use App\Communication\CommunicationManager;
use App\Communication\Contracts\CommunicationProvider;
use App\Communication\OutboundMessage;
use App\Communication\ProviderResult;
use App\Enums\CommunicationChannel;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * A recording provider for automated tests (M16). Captures every message and
 * lets a test script the next result — no external call ever happens. Swapped
 * in via {@see CommunicationManager::fake()}.
 */
final class FakeProvider implements CommunicationProvider
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    /** @var array<int, ProviderResult> queued results, FIFO */
    private array $scriptedResults = [];

    private bool $throwNext = false;

    private ?string $throwMessage = null;

    public function __construct(private readonly CommunicationChannel $channel) {}

    public function channel(): CommunicationChannel
    {
        return $this->channel;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function send(OutboundMessage $message): ProviderResult
    {
        $this->sent[] = $message;

        if ($this->throwNext) {
            $this->throwNext = false;
            throw new \RuntimeException($this->throwMessage ?? 'fake transport error');
        }

        return array_shift($this->scriptedResults)
            ?? ProviderResult::accepted('fake-'.Str::uuid()->toString(), confirmsDelivery: true);
    }

    public function willReturn(ProviderResult $result): self
    {
        $this->scriptedResults[] = $result;

        return $this;
    }

    public function willThrow(string $message = 'fake transport error'): self
    {
        $this->throwNext = true;
        $this->throwMessage = $message;

        return $this;
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent, "Expected {$expected} messages via the fake provider.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Expected no messages via the fake provider.');
    }
}
