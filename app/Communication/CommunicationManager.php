<?php

declare(strict_types=1);

namespace App\Communication;

use App\Communication\Contracts\CommunicationProvider;
use App\Communication\Providers\EmailProvider;
use App\Communication\Providers\FakeProvider;
use App\Communication\Providers\LogProvider;
use App\Enums\CommunicationChannel;
use App\Providers\AppServiceProvider;
use InvalidArgumentException;

/**
 * Resolves the {@see CommunicationProvider} bound to a channel from
 * `config('communication.channels.<channel>.provider')` (M16). Registered as a
 * singleton in {@see AppServiceProvider}. Domain code depends on
 * this, never on a concrete provider.
 *
 * `fake()` swaps every channel to a recording provider for tests.
 */
class CommunicationManager
{
    /** @var array<string, CommunicationProvider> */
    private array $resolved = [];

    private ?FakeProvider $fake = null;

    /** @var array<string, class-string<CommunicationProvider>|callable> */
    private array $custom = [];

    public function for(CommunicationChannel $channel): CommunicationProvider
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return $this->resolved[$channel->value] ??= $this->build($channel);
    }

    /** Register an extra provider driver at runtime (used by later M16 phases). */
    public function extend(string $driver, callable $factory): void
    {
        $this->custom[$driver] = $factory;
    }

    public function fake(?CommunicationChannel $channel = null): FakeProvider
    {
        return $this->fake ??= new FakeProvider($channel ?? CommunicationChannel::Email);
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    private function build(CommunicationChannel $channel): CommunicationProvider
    {
        $driver = (string) config("communication.channels.{$channel->value}.provider", 'log');

        if (isset($this->custom[$driver])) {
            return ($this->custom[$driver])($channel);
        }

        return match ($driver) {
            'log' => new LogProvider($channel),
            'mail' => $channel === CommunicationChannel::Email
                ? new EmailProvider
                : throw new InvalidArgumentException("The 'mail' provider only serves the email channel."),
            default => throw new InvalidArgumentException("Unknown communication provider [{$driver}] for {$channel->value}."),
        };
    }
}
