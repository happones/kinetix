<?php

declare(strict_types=1);

namespace Happones\Kinetix\Reports;

use Happones\Kinetix\Exports\Exporter;

/**
 * A report definition: an {@see Exporter} whose output is emailed to recipients
 * on a schedule.
 *
 *     ScheduledReport::make('daily-orders')
 *         ->exporter(OrdersExporter::class)
 *         ->frequency('daily')
 *         ->to(['ops@acme.com'])
 *         ->subject('Daily orders')
 *         ->parameters(['status' => 'paid']);
 */
class ScheduledReport
{
    /** @var class-string<Exporter>|null */
    protected ?string $exporter = null;

    protected string $frequency = 'daily';

    /** @var array<int, string> */
    protected array $recipients = [];

    protected ?string $subject = null;

    /** @var array<string, mixed> */
    protected array $parameters = [];

    protected bool $enabled = true;

    public function __construct(protected string $key) {}

    public static function make(string $key): static
    {
        return new static($key);
    }

    /**
     * @param class-string<Exporter> $exporter
     */
    public function exporter(string $exporter): static
    {
        $this->exporter = $exporter;

        return $this;
    }

    /**
     * 'daily' | 'weekly' | 'monthly' (or any label you schedule the command with).
     */
    public function frequency(string $frequency): static
    {
        $this->frequency = $frequency;

        return $this;
    }

    /**
     * @param array<int, string>|string $recipients
     */
    public function to(array|string $recipients): static
    {
        $this->recipients = is_array($recipients) ? array_values($recipients) : [$recipients];

        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function parameters(array $parameters): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    public function enabled(bool $enabled = true): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return class-string<Exporter>
     */
    public function getExporter(): string
    {
        if ($this->exporter === null) {
            throw new \RuntimeException("Scheduled report [{$this->key}] has no exporter.");
        }

        return $this->exporter;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    /**
     * @return array<int, string>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getSubject(): string
    {
        return $this->subject ?? (string) str($this->key)->headline();
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
